#!/bin/bash
# Legacy alias — use deploy-smm.sh or scripts/deploy-cpanel.sh
exec "$(dirname "$0")/deploy-cpanel.sh"
