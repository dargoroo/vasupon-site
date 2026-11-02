import { Client } from "@modelcontextprotocol/sdk/client/index.js";
import { StdioClientTransport } from "@modelcontextprotocol/sdk/client/stdio.js";

const transport = new StdioClientTransport({
  command: process.execPath,
  args: [new URL("./server.mjs", import.meta.url).pathname]
});
const client = new Client({ name: "rbru-ssh-smoke-test", version: "1.0.0" });

try {
  await client.connect(transport);
  const tools = await client.listTools();
  const status = await client.callTool({ name: "server_status", arguments: {} });
  console.log(JSON.stringify({
    tools: tools.tools.map(tool => tool.name),
    status: status.content?.[0]?.text || ""
  }, null, 2));
} finally {
  await client.close();
}
