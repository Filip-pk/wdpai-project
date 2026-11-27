FROM nginx:stable-alpine

RUN apk add --no-cache openssl

COPY docker/nginx/nginx.conf /etc/nginx/templates/default.conf.template
COPY docker/nginx/generate-certificate.sh /docker-entrypoint.d/15-anivibe-certificate.sh
RUN sed -i 's/\r$//' /docker-entrypoint.d/15-anivibe-certificate.sh \
    && chmod +x /docker-entrypoint.d/15-anivibe-certificate.sh
WORKDIR /app
