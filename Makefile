.PHONY: lint test package-revolut clean

lint:
	@find payments addons servers -type f -name '*.php' -print0 | xargs -0 -r -n1 php -l

test: lint
	@php payments/revolut/tests/selftest.php

package-revolut:
	@./scripts/package-module.sh payments/revolut revolut-whmcs

clean:
	@rm -rf dist
