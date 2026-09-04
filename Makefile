.PHONY: lint test package-all package-revolut package-sales-tracker package-colete-online package-logicboxes-tools package-supermailverify package-supportpin clean

lint:
	@find payments addons servers -type f -name '*.php' -print0 | xargs -0 -r -n1 php -l

test: lint
	@php payments/revolut/tests/selftest.php
	@php addons/sales-tracker/tests/selftest.php
	@php addons/colete-online/tests/selftest.php
	@php addons/logicboxes-tools/tests/selftest.php
	@php addons/logicboxes-tools/tests/reinclude.php
	@php addons/logicboxes-tools/tests/reinclude-hooks.php

package-all: package-revolut package-sales-tracker package-colete-online package-logicboxes-tools package-supermailverify package-supportpin

package-revolut:
	@./scripts/package-module.sh payments/revolut revolut-whmcs

package-sales-tracker:
	@./scripts/package-module.sh addons/sales-tracker serverspan-sales-tracker-whmcs

package-colete-online:
	@./scripts/package-module.sh addons/colete-online serverspan-colete-online-whmcs

package-logicboxes-tools:
	@./scripts/package-module.sh addons/logicboxes-tools serverspan-logicboxes-tools-whmcs

package-supermailverify:
	@./scripts/package-module.sh addons/supermailverify serverspan-super-email-verification-whmcs

package-supportpin:
	@./scripts/package-module.sh addons/supportpin serverspan-support-pin-whmcs

clean:
	@rm -rf dist
