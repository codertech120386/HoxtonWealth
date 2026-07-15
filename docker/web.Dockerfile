# nginx serving Laravel's public/ dir and proxying PHP to the `app` service (fastcgi app:9000).
# Code + nginx config are BAKED IN (no volume mounts) so the container is self-contained. This is
# the group's PUBLIC service (port 80 -> the environment's URL).
FROM nginx:1.27-alpine

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY . /app

EXPOSE 80
