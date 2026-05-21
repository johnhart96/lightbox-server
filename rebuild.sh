#!/bin/bash
docker compose down
docker compose build
chmod 777 -R /mnt/lightbox-server
docker compose up -d
