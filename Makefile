PORT ?= 8000
start:
	PHP_CLI_SERVER_WORKERS=5 php -S 0.0.0.0:$(PORT) -t public

stop:
	-fuser -k $(PORT)/tcp

install:
	composer install

lint:
	composer exec phpcs -- --standard=PSR12 public