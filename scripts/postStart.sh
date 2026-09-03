#!/bin/bash
#
# Runs after fppd starts. A restart is when configuration most often just
# changed, so a snapshot goes out shortly after — in the background, after
# giving fppd and Apache a moment, so starting the show is never held up.

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
( sleep 90; /bin/bash "${DIR}/sync.sh" auto ) >/dev/null 2>&1 &
