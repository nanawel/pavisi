PHP_INI_ARGS ?=
MEMORY_LIMIT ?= 256M

.PHONY: config
config:
	docker-compose config

.PHONY: build
build:
	COMPOSE_FILE=docker-compose.build.yml \
		docker-compose build $(args)
