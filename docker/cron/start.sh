#!/bin/sh
# Starts crond for the scheduled tasks in docker/cron/crontab. Cron jobs begin with an
# empty environment, so the variables the PHP task needs are saved to a root-only file
# that each job loads first.
set -eu

umask 077
export -p | grep -E "^export (POSTGRES|MAIL|OLLAMA)_" > /run/anivibe-cron.env

exec crond -f -l 6
