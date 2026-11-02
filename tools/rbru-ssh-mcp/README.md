# RBRU Server MCP

Local STDIO MCP server for scoped access to:

- `cpe.rbru.ac.th` through the existing SSH alias `cpe`
- `teach.rbru.ac.th:21` through FTP

It does not store passwords or private keys in source code. SSH authentication is handled by `~/.ssh/config`; the Teach FTP password is read from macOS Keychain at runtime.

Configure the Teach FTP credential once in Terminal:

```sh
tools/rbru-ssh-mcp/setup-teach-ftp-keychain.sh
```

Approved remote roots:

- `apps`: `/home/www/cpe-apps`
- `cms`: `/home/www/cpe-cms`
- `camp`: `/opt/apps/cpecamp_q`

Available tools:

- `server_status`
- `list_directory`
- `read_text_file`
- `search_text`
- `git_status`
- `docker_status`
- `upload_project_file`
- `teach_ftp_status`
- `teach_list_directory`
- `teach_read_text_file`
- `teach_upload_project_file`

Both upload tools only read local files inside the `vasupon-site` repository. The SSH upload creates a timestamped backup before replacement. FTP upload replaces its destination directly because the FTP account does not expose a reliable atomic backup operation.
