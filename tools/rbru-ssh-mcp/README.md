# RBRU SSH MCP

Local STDIO MCP server for scoped access to `cpe.rbru.ac.th` through the existing SSH alias `cpe`.

It does not store passwords or private keys. Authentication is handled by `~/.ssh/config` and the existing SSH key.

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

The upload tool only reads local files inside the `vasupon-site` repository and creates a timestamped backup before replacing an existing remote file.
