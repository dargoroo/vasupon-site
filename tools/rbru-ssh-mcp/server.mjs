#!/usr/bin/env node

import { spawn } from "node:child_process";
import { createReadStream } from "node:fs";
import { realpath, stat } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { z } from "zod";

const SSH_TARGET = "cpe";
const PROJECT_ROOT = "/Users/vasuponphueaknumpol/Documents/GitHub/vasupon-site";
const REMOTE_ROOTS = Object.freeze({
  apps: "/home/www/cpe-apps",
  cms: "/home/www/cpe-cms",
  camp: "/opt/apps/cpecamp_q"
});
const MAX_OUTPUT_BYTES = 300_000;

function shellQuote(value) {
  return `'${String(value).replaceAll("'", `'"'"'`)}'`;
}

function remotePath(rootName, relativePath = ".") {
  const root = REMOTE_ROOTS[rootName];
  if (!root) throw new Error(`Unknown root: ${rootName}`);
  const raw = String(relativePath || ".");
  if (raw.includes("\0") || path.posix.isAbsolute(raw)) {
    throw new Error("Path must be relative to the selected server root");
  }
  const normalized = path.posix.normalize(raw);
  if (normalized === ".." || normalized.startsWith("../")) {
    throw new Error("Path cannot leave the selected server root");
  }
  return normalized === "." ? root : path.posix.join(root, normalized);
}

function runProcess(command, args, { inputStream, timeoutMs = 60_000 } = {}) {
  return new Promise((resolve, reject) => {
    const child = spawn(command, args, { stdio: [inputStream ? "pipe" : "ignore", "pipe", "pipe"] });
    let stdout = Buffer.alloc(0);
    let stderr = Buffer.alloc(0);
    let truncated = false;

    const collect = (current, chunk) => {
      if (current.length >= MAX_OUTPUT_BYTES) {
        truncated = true;
        return current;
      }
      const remaining = MAX_OUTPUT_BYTES - current.length;
      if (chunk.length > remaining) truncated = true;
      return Buffer.concat([current, chunk.subarray(0, remaining)]);
    };

    child.stdout.on("data", chunk => { stdout = collect(stdout, chunk); });
    child.stderr.on("data", chunk => { stderr = collect(stderr, chunk); });
    child.on("error", reject);

    const timer = setTimeout(() => {
      child.kill("SIGTERM");
      reject(new Error(`Command timed out after ${timeoutMs} ms`));
    }, timeoutMs);

    if (inputStream) {
      inputStream.on("error", error => child.stdin.destroy(error));
      inputStream.pipe(child.stdin);
    }

    child.on("close", code => {
      clearTimeout(timer);
      const out = stdout.toString("utf8");
      const err = stderr.toString("utf8");
      if (code !== 0) {
        reject(new Error(err.trim() || out.trim() || `${command} exited with code ${code}`));
        return;
      }
      resolve(`${out}${err ? `\n[stderr]\n${err}` : ""}${truncated ? "\n[output truncated]" : ""}`.trim());
    });
  });
}

function ssh(remoteCommand, options = {}) {
  return runProcess("ssh", [
    "-o", "BatchMode=yes",
    "-o", "ConnectTimeout=10",
    "-o", "ServerAliveInterval=30",
    SSH_TARGET,
    remoteCommand
  ], options);
}

async function resolveLocalProjectFile(relativePath) {
  const raw = String(relativePath || "");
  if (!raw || path.isAbsolute(raw) || raw.includes("\0")) {
    throw new Error("local_path must be relative to the vasupon-site repository");
  }
  const candidate = path.resolve(PROJECT_ROOT, raw);
  const resolved = await realpath(candidate);
  const rootWithSeparator = `${PROJECT_ROOT}${path.sep}`;
  if (resolved !== PROJECT_ROOT && !resolved.startsWith(rootWithSeparator)) {
    throw new Error("Local file cannot leave the vasupon-site repository");
  }
  const info = await stat(resolved);
  if (!info.isFile()) throw new Error("Local path must point to a regular file");
  return { resolved, size: info.size };
}

function textResult(text) {
  return { content: [{ type: "text", text: String(text || "(no output)") }] };
}

function errorResult(error) {
  return {
    isError: true,
    content: [{ type: "text", text: error instanceof Error ? error.message : String(error) }]
  };
}

const rootSchema = z.enum(["apps", "cms", "camp"])
  .describe("Server area: apps=/home/www/cpe-apps, cms=/home/www/cpe-cms, camp=/opt/apps/cpecamp_q");

const server = new McpServer({ name: "rbru-ssh", version: "1.0.0" });

server.registerTool("server_status", {
  title: "RBRU server status",
  description: "Check SSH connectivity, disk space, uptime, and running Docker containers on cpe.rbru.ac.th.",
  inputSchema: {},
  annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true }
}, async () => {
  try {
    const output = await ssh("printf 'HOST: '; hostname; printf 'USER: '; id -un; printf 'UPTIME: '; uptime -p; printf '\\nDISK\\n'; df -h /home/www /opt 2>/dev/null; printf '\\nCONTAINERS\\n'; docker ps --format '{{.Names}}\\t{{.Status}}' 2>/dev/null || true");
    return textResult(output);
  } catch (error) {
    return errorResult(error);
  }
});

server.registerTool("list_directory", {
  title: "List server directory",
  description: "List one directory inside an approved RBRU web root. Paths cannot leave the selected root.",
  inputSchema: {
    root: rootSchema,
    path: z.string().default(".").describe("Relative directory path inside the selected root")
  },
  annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true }
}, async ({ root, path: relativePath }) => {
  try {
    const target = remotePath(root, relativePath);
    const output = await ssh(`test -d ${shellQuote(target)} && find ${shellQuote(target)} -mindepth 1 -maxdepth 1 -printf '%y\\t%s\\t%TY-%Tm-%Td %TH:%TM\\t%f\\n' | sort`);
    return textResult(output);
  } catch (error) {
    return errorResult(error);
  }
});

server.registerTool("read_text_file", {
  title: "Read server text file",
  description: "Read the beginning of a UTF-8 text file inside an approved RBRU web root.",
  inputSchema: {
    root: rootSchema,
    path: z.string().min(1).describe("Relative file path inside the selected root"),
    max_bytes: z.number().int().min(1).max(200_000).default(50_000)
  },
  annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true }
}, async ({ root, path: relativePath, max_bytes: maxBytes }) => {
  try {
    const target = remotePath(root, relativePath);
    const output = await ssh(`test -f ${shellQuote(target)} && head -c ${maxBytes} -- ${shellQuote(target)}`);
    return textResult(output);
  } catch (error) {
    return errorResult(error);
  }
});

server.registerTool("search_text", {
  title: "Search server files",
  description: "Search literal text recursively within an approved RBRU web directory. Returns at most the requested number of matches.",
  inputSchema: {
    root: rootSchema,
    path: z.string().default(".").describe("Relative directory path inside the selected root"),
    query: z.string().min(1).max(200).describe("Literal text to search for"),
    limit: z.number().int().min(1).max(200).default(50)
  },
  annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true }
}, async ({ root, path: relativePath, query, limit }) => {
  try {
    const target = remotePath(root, relativePath);
    const command = `if command -v rg >/dev/null 2>&1; then rg -n -F --hidden -g '!.git/**' -g '!*.sql' -g '!*.log' -m ${limit} -- ${shellQuote(query)} ${shellQuote(target)}; else grep -RInF --exclude='*.sql' --exclude='*.log' --exclude-dir=.git -m ${limit} -- ${shellQuote(query)} ${shellQuote(target)}; fi`;
    const output = await ssh(command);
    return textResult(output || "No matches");
  } catch (error) {
    if (String(error?.message || error).includes("exited with code 1")) return textResult("No matches");
    return errorResult(error);
  }
});

server.registerTool("git_status", {
  title: "Server Git status",
  description: "Show branch, working-tree status, and latest commit for a Git repository inside an approved RBRU root.",
  inputSchema: {
    root: rootSchema,
    path: z.string().default(".").describe("Relative Git repository directory")
  },
  annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true }
}, async ({ root, path: relativePath }) => {
  try {
    const target = remotePath(root, relativePath);
    const output = await ssh(`git -C ${shellQuote(target)} status --short --branch && git -C ${shellQuote(target)} log -1 --date=iso --pretty='format:%h %ad %s'`);
    return textResult(output);
  } catch (error) {
    return errorResult(error);
  }
});

server.registerTool("docker_status", {
  title: "Docker container status",
  description: "List running Docker containers and their status on the RBRU CPE server.",
  inputSchema: {},
  annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true }
}, async () => {
  try {
    const output = await ssh("docker ps --format 'table {{.Names}}\\t{{.Image}}\\t{{.Status}}\\t{{.Ports}}'");
    return textResult(output);
  } catch (error) {
    return errorResult(error);
  }
});

server.registerTool("upload_project_file", {
  title: "Upload project file to RBRU server",
  description: "Upload one file from the local vasupon-site repository into an approved RBRU web root. Existing remote files are backed up before replacement.",
  inputSchema: {
    local_path: z.string().min(1).describe("Path relative to the local vasupon-site repository"),
    root: rootSchema,
    remote_path: z.string().min(1).describe("Destination path relative to the selected server root")
  },
  annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false }
}, async ({ local_path: localPath, root, remote_path: remoteRelativePath }) => {
  try {
    const local = await resolveLocalProjectFile(localPath);
    const remote = remotePath(root, remoteRelativePath);
    const remoteDir = path.posix.dirname(remote);
    const tempPath = `${remote}.mcp-upload-${process.pid}`;
    const timestamp = new Date().toISOString().replace(/[-:TZ.]/g, "").slice(0, 14);
    const backupPath = `${remote}.bak-${timestamp}`;
    const command = [
      `mkdir -p ${shellQuote(remoteDir)}`,
      `if test -e ${shellQuote(remote)}; then cp -p -- ${shellQuote(remote)} ${shellQuote(backupPath)}; fi`,
      `cat > ${shellQuote(tempPath)}`,
      `{ chmod --reference=${shellQuote(remote)} ${shellQuote(tempPath)} 2>/dev/null || chmod 0644 ${shellQuote(tempPath)}; }`,
      `mv -f -- ${shellQuote(tempPath)} ${shellQuote(remote)}`,
      `printf 'uploaded\\t%s bytes\\t%s\\n' ${local.size} ${shellQuote(remote)}`
    ].join(" && ");
    const output = await ssh(command, { inputStream: createReadStream(local.resolved), timeoutMs: 120_000 });
    return textResult(output);
  } catch (error) {
    return errorResult(error);
  }
});

const transport = new StdioServerTransport();
await server.connect(transport);
