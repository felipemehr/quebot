FROM php:8.1-cli

WORKDIR /app
COPY . /app

EXPOSE 8080

CMD php -S 0.0.0.0:${PORT:-8080} -t /app
