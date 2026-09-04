.PHONY: lint test package-revolut package-sales-tracker clean

lint:
	@find payments addons servers -type f -name '*.php' -print0 | xargs -0 -r -n1 php -l

test: lint
	@php payments/revolut/tests/selftest.php
	@php addons/sales-tracker/tests/selftest.php

package-revolut:
	@./scripts/package-module.sh payments/revolut revolut-whmcs

package-sales-tracker:
	@./scripts/package-module.sh addons/sales-tracker serverspan-sales-tracker-whmcs

clean:
	@rm -rf dist
