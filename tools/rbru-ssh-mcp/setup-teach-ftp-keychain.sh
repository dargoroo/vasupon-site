#!/bin/zsh

set -eu

account="vasupon_p"
service="codex-rbru-teach-ftp"

read -rs "ftp_password?FTP password for ${account}@teach.rbru.ac.th:21: "
printf '\n'

if [[ -z "${ftp_password}" ]]; then
  print -u2 "Password cannot be empty."
  exit 1
fi

/usr/bin/security add-generic-password \
  -U \
  -a "${account}" \
  -s "${service}" \
  -w "${ftp_password}"

unset ftp_password
printf 'Stored the Teach FTP credential in macOS Keychain.\n'
