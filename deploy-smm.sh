#!/bin/bash
set -e
eval "$(ssh-agent -s)"
ssh-add ~/.ssh/id_rsa
cd /home/smmturk/repositories/smm-turk-panel
git pull origin main
rsync -av --delete --exclude=".git" --exclude=".cpanel.yml" --exclude="config.php" --chmod=D755,F644 ./ /home/smmturk/public_html/
