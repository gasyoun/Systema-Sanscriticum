#!/bin/bash
# backrest-hook-notify.sh — H6320: adapter from backrest COMMAND hook args to the
# shared hermes Telegram sink. backrest renders its hook template with Go
# templates and runs the result via sh; this wrapper joins the shell-escaped
# argv back into one message so hermes_notify gets a single clean argument.
# Installed at /usr/local/sbin/backrest-hook-notify.sh (0755) on vps92.
set -u
msg="backrest vps92: $*"
/home/hermes/bin/hermes_notify.sh page "$msg"
