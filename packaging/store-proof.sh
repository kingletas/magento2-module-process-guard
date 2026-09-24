#!/usr/bin/env bash
#
# store-proof.sh: assert, against a real Magento, the things this module claims
# that no unit test can reach.
#
# Run by bin/store-proof, which has already installed the module the way
# somebody else would, enabled it, and run setup:upgrade twice. See
# `bin/store-proof -h` for what this is given.
#
# WHAT THIS IS AIMED AT. The unit suite proves the gate's logic exactly and says
# itself that it cannot prove the plugin declarations still bind to Magento's
# signatures. So every claim here is put to Magento: which plugins the container
# built, what the two console commands print, what the configuration says with
# nothing stored, and what the guard wrote down while a real cart collected its
# totals.
#
# Assertions are made in BOTH directions. A breakdown that is always there and
# one that is never there both look like a working switch if only one side is
# ever looked at, and a kill switch is only proved by an observer that ran
# before it was named and did not run after.
#
# The cart is invented and never saved. An empty cart still runs every totals
# collector once per address, which is what the breakdown times, so nothing is
# written to the store for it.
#
# THE STORE'S OWN SETTINGS. A store may already carry this module, and its
# operator may have settings of their own. Every row under the module's section
# is written to a file before anything else, set aside so the proof reads what
# the module ships, and put back exactly on the way out, however the run ends.
# An invented row is planted before the capture, so the restore is proved on
# every store, including one that had nothing of its own to put back.
#
# Settings are written at default scope, which is where the README's config:set
# writes and the only scope these admin fields show in. They are read back as
# the effective value on the default store view, which inherits them.
#
# THE STORE'S OWN MODULE. Budgets reach the guard as an array wherever a store
# tuned one in its own di.xml, and wherever a store upgrading from 2.0.0 still
# has 2.0.0's wiring cached. The proof builds that shape on every store with a
# module of the store's own under app/code, and takes it out again: its files,
# the module list as it was, and any row setup:upgrade gave it.

set -euo pipefail

MODULE_NAME="Kingletas_ProcessGuard"
SECTION="kingletas_processguard"
FLAGS="${STORE_PROOF_STORE}/local.d/store-proof-process-guard-flags.php"
TOTALS="${STORE_PROOF_STORE}/local.d/store-proof-process-guard-totals.php"
SAVED="${STORE_PROOF_STORE}/local.d/store-proof-process-guard-config.sql"
# Every row under the section and nothing that only shares a prefix with it,
# which LIKE cannot promise: an underscore there matches any character.
SECTION_ROWS="LEFT(path, CHAR_LENGTH('${SECTION}/')) = '${SECTION}/'"
# The paths this proof writes.
WRITTEN=(general/enabled enforcement/disabled_observers enforcement/advisory_observers
	enforcement/critical_observers reporting/summaries_enabled reporting/totals_detail_enabled)
# Planted at a scope other than default, with backslashes in the value as an
# observer class has, written here as SQL reads it.
PLANTED_PATH="${SECTION}/storeproof/planted"
PLANTED_SQL_VALUE='Vendor\\Module\\Observer\\Invented'
TOTALS_EVENT="sales_quote_collect_totals_before"
INVENTED_OBSERVER="storeproof_no_such_observer"
ASKER="StoreProofCartPage::showTotals"
# One more than the budget's four, so the fifth collection is a repeat.
COLLECTIONS=5
STORE_VENDOR_DIR="${STORE_PROOF_STORE}/app/code/StoreProof"
STORE_MODULE="StoreProof_GuardBudgets"
STORE_MODULE_DIR="${STORE_VENDOR_DIR}/GuardBudgets"
MODULE_LIST="${STORE_PROOF_STORE}/app/etc/config.php"
MODULE_LIST_BEFORE="${STORE_PROOF_STORE}/local.d/store-proof-process-guard-config-php.before"
BUDGETS="${STORE_PROOF_STORE}/local.d/store-proof-process-guard-budgets.php"
INVENTED_PROCESS="storeproof.invented_export"
failures=0
planted=0
captured=0
store_module=0
made_code_dir=0

step() { printf '    %s\n' "$*"; }
bad() { printf '    FAILED: %s\n' "$*" >&2; failures=$((failures + 1)); }

# Without a terminal on stdin, the store runs bin/magento without one too, so the
# output carries no carriage returns and a command's own exit status comes back.
magento() { $STORE_PROOF_MAGENTO "$@" < /dev/null 2>&1 | tr -d '\r'; }

value() { $STORE_PROOF_SQL 2>&1 <<< "$1" | tail -1 | tr -d '[:space:]'; }

# Reads key=value out of a report line without a regex, so no sed dialect gets
# to decide whether a proof passes.
field() {
	local token
	for token in $1; do
		case "$token" in
			"$2"=*) printf '%s' "${token#*=}"; return 0 ;;
		esac
	done
	return 1
}

# Sets each setting=value below this module's section at default scope, or
# removes that row when the value is empty so the XML default applies again, then
# flushes the cache, which is where the README says a change takes effect.
configure_guard() {
	local pair path
	for pair in "$@"; do
		path="${SECTION}/${pair%%=*}"
		if [ -n "${pair#*=}" ]; then
			magento config:set --scope=default "$path" "${pair#*=}" >/dev/null \
				|| bad "config:set refused ${path}"
		else
			$STORE_PROOF_SQL >/dev/null \
				<<< "DELETE FROM core_config_data WHERE path = '${path}' AND scope = 'default' AND scope_id = 0;" \
				|| bad "could not remove ${path}"
		fi
	done
	magento cache:flush >/dev/null || bad "cache:flush failed, so the next step may read the old settings"
}

# What the policy listing says the guard does to one observer, once per row it
# appears on. The first cell is the observer, the third is the guard's answer.
guard_does() {
	awk -F'|' -v name="$1" '{
		gsub(/^ +| +$/, "", $2)
		gsub(/^ +| +$/, "", $4)
		if ($2 == name) print $4
	}' <<< "$2" | sort -u
}

# The report is the last line. A run that dies before it prints its whole output
# instead, so a PHP fatal reads as one rather than as a row of missing fields.
collect() {
	local out
	if ! out="$($STORE_PROOF_PHP /app/local.d/store-proof-process-guard-totals.php 2>&1)"; then
		printf '%s\n' "$out" | sed 's/^/      /' >&2
	fi
	tail -1 <<< "$out"
}

# The section's rows as one value: how many, and an order-free hash over every
# scope, scope id, path and value, so what is put back can be compared with what
# was found.
section_fingerprint() {
	value "SELECT CONCAT(COUNT(*), ':', IFNULL(BIT_XOR(CAST(CONV(SUBSTRING(MD5(
		CONCAT_WS(0x1f, scope, scope_id, path, IFNULL(HEX(value), 'NULL'))), 1, 16), 16, 10) AS UNSIGNED)), 0))
		FROM core_config_data WHERE ${SECTION_ROWS};"
}

path_rows() { value "SELECT COUNT(*) FROM core_config_data WHERE path = '${SECTION}/$1';"; }

planted_rows() {
	value "SELECT COUNT(*) FROM core_config_data
		WHERE scope = 'websites' AND scope_id = 1 AND path = '${PLANTED_PATH}' AND value = '${PLANTED_SQL_VALUE}';"
}

# Each row comes back from MariaDB as the INSERT that recreates it, every field
# hex-encoded, because the client escapes backslashes in what it prints and
# observer class names are full of them.
capture_config() {
	local rows
	rows="$($STORE_PROOF_SQL <<-SQL
		SELECT CONCAT('INSERT INTO core_config_data (scope, scope_id, path, value) VALUES (UNHEX(''',
			HEX(scope), '''), ', scope_id, ', UNHEX(''', HEX(path), '''), ',
			IF(value IS NULL, 'NULL', CONCAT('UNHEX(''', HEX(value), ''')')), ');') AS row_sql
		FROM core_config_data WHERE ${SECTION_ROWS} ORDER BY config_id;
	SQL
	)" || return 1
	grep '^INSERT INTO ' <<< "$rows" > "$SAVED" || true
}

# Everything under the section goes, then every captured row returns, in one
# transaction, so an insert that fails leaves the rows it found rather than none.
restore_config() {
	{
		echo "START TRANSACTION;"
		echo "DELETE FROM core_config_data WHERE ${SECTION_ROWS};"
		cat "$SAVED"
		echo "COMMIT;"
	} | $STORE_PROOF_SQL >/dev/null
}

remove_planted() {
	$STORE_PROOF_SQL >/dev/null \
		<<< "DELETE FROM core_config_data WHERE scope = 'websites' AND scope_id = 1 AND path = '${PLANTED_PATH}';"
}

# The store's own module out again, and the module list exactly as it was
# before the module was enabled. Only directories this proof made are removed.
remove_store_module() {
	cp "$MODULE_LIST_BEFORE" "$MODULE_LIST" || return 1
	rm -rf "$STORE_VENDOR_DIR"
	if [ "$made_code_dir" = "1" ]; then
		rmdir "${STORE_PROOF_STORE}/app/code" 2>/dev/null || true
	fi
	$STORE_PROOF_SQL >/dev/null <<< "DELETE FROM setup_module WHERE module = '${STORE_MODULE}';"
}

# A captured file is kept when what it holds could not be put back, because it
# is then the only copy.
cleanup() {
	local lost=0
	if [ "$store_module" = "1" ] && ! remove_store_module >/dev/null 2>&1; then
		lost=1
		echo "    FAILED: ${STORE_MODULE} could not be taken out; the module list it replaced is kept in ${MODULE_LIST_BEFORE}" >&2
	else
		rm -f "$MODULE_LIST_BEFORE"
	fi
	if [ "$captured" = "1" ] && ! restore_config >/dev/null 2>&1; then
		lost=1
		echo "    FAILED: the store's settings could not be put back; they are kept in ${SAVED}, whose invented ${PLANTED_PATH} row can be left out" >&2
	else
		rm -f "$SAVED"
	fi
	if [ "$planted" = "1" ]; then
		remove_planted >/dev/null 2>&1 || true
	fi
	$STORE_PROOF_MAGENTO cache:flush < /dev/null >/dev/null 2>&1 || true
	rm -f "$FLAGS" "$TOTALS" "$BUDGETS"
	if [ "$lost" = "1" ]; then
		exit 1
	fi
}
trap cleanup EXIT

# The store's own module goes under app/code/StoreProof, and the proof removes
# that directory whole, so it refuses a store that already has one.
if [ -e "$STORE_VENDOR_DIR" ]; then
	echo "    this store already has ${STORE_VENDOR_DIR#"${STORE_PROOF_STORE}"/}, which this proof would create and remove, so nothing was touched" >&2
	exit 1
fi

# --- the store's own settings, set aside -------------------------------------

step "planting an invented setting, so the restore has something to put back"
planted=1
$STORE_PROOF_SQL >/dev/null <<< "INSERT INTO core_config_data (scope, scope_id, path, value)
	VALUES ('websites', 1, '${PLANTED_PATH}', '${PLANTED_SQL_VALUE}')
	ON DUPLICATE KEY UPDATE value = VALUES(value);" \
	|| { echo "    could not plant ${PLANTED_PATH}" >&2; exit 1; }

step "capturing every setting under ${SECTION}"
before="$(section_fingerprint)"
declare -A rows_before=()
for path in "${WRITTEN[@]}"; do
	rows_before[$path]="$(path_rows "$path")"
done
capture_config || { echo "    could not read the settings under ${SECTION}, so nothing was set aside" >&2; exit 1; }
captured_rows="$(grep -c '^INSERT INTO ' "$SAVED" || true)"

# A capture that disagrees with the count is not a copy, and setting rows aside
# on the strength of it could lose one.
if [ -z "$before" ] || [ "${before%%:*}" != "$captured_rows" ]; then
	echo "    the section holds '${before%%:*}' row(s) and ${captured_rows} were captured, so nothing was set aside" >&2
	exit 1
fi
step "  ${captured_rows} row(s) captured and set aside until the end"
captured=1
$STORE_PROOF_SQL >/dev/null <<< "DELETE FROM core_config_data WHERE ${SECTION_ROWS};"
magento cache:flush >/dev/null

# --- what installing it wired ------------------------------------------------

step "the module reports itself enabled"
status_text="$(magento module:status "$MODULE_NAME" || true)"
grep -qi 'enabled' <<< "$status_text" || bad "Magento does not report ${MODULE_NAME} as enabled"

step "Magento lists both console commands"
commands="$(magento list --raw || true)"
for name in kingletas:process-guard:policies kingletas:process-guard:check; do
	grep -q "^${name} " <<< "$commands" || bad "bin/magento does not list ${name}"
done

# Asked of the container rather than of di.xml: the XML being well formed says
# nothing about the object manager reading it, and a plugin whose class cannot
# be loaded is dropped from the list without a word. The collector seam is asked
# of a real collector, because the plugin is declared on the interface and only
# inheritance puts it on the class Magento actually calls.
step "the container built every seam the README names"
seams=(
	'Magento\Framework\Event\Invoker\InvokerDefault|Kingletas\ProcessGuard\Plugin\Event\GuardedInvoker'
	'Magento\Quote\Model\Quote\TotalsCollector|Kingletas\ProcessGuard\Plugin\Quote\GuardedTotalsCollector'
	'Magento\Quote\Model\Quote\Address\Total\Subtotal|Kingletas\ProcessGuard\Plugin\Quote\GuardedTotalCollector'
	'Magento\Catalog\Api\ProductRepositoryInterface|Kingletas\ProcessGuard\Plugin\Catalog\GuardedProductSave'
	'Magento\Framework\MessageQueue\ConsumerInterface|Kingletas\ProcessGuard\Plugin\MessageQueue\GuardedConsumer'
	'Magento\Framework\MessageQueue\CallbackInvokerInterface|Kingletas\ProcessGuard\Plugin\MessageQueue\PerMessageUnit'
	'Magento\Framework\MessageQueue\QueueInterface|Kingletas\ProcessGuard\Plugin\MessageQueue\PerMessageUnit'
	'Magento\Cron\Model\Schedule|Kingletas\ProcessGuard\Plugin\Cron\PerJobUnit'
)
for seam in "${seams[@]}"; do
	type="${seam%%|*}"
	plugin="${seam#*|}"
	wiring="$(magento dev:di:info "$type" || true)"
	grep -qF "$plugin" <<< "$wiring" || bad "the container built ${type} without ${plugin}"
done

# --- the shipped defaults change nothing about what runs --------------------

# The README's safety claim: measurement is on, because with nothing classified
# every observer still runs unchanged, and everything that costs time or changes
# what runs is off.
#
# Asked of Magento rather than of config:show, which prints nothing for a path
# whose only value is an XML default.
cat > "$FLAGS" <<-'PHP'
	<?php
	declare(strict_types=1);

	/**
	 * Prints each setting's effective value on the default store view: a switch
	 * as 0 or 1, a list as the length of what it holds.
	 */

	require '/app/app/bootstrap.php';

	use Magento\Framework\App\Bootstrap;
	use Magento\Framework\App\Config\ScopeConfigInterface;
	use Magento\Store\Model\ScopeInterface;

	$config = Bootstrap::create(BP, $_SERVER)->getObjectManager()->get(ScopeConfigInterface::class);

	foreach (array_slice($argv, 1) as $argument) {
	    [$kind, $path] = explode(':', $argument, 2);
	    $full = 'kingletas_processguard/' . $path;

	    if ($kind === 'list') {
	        $value = strlen(trim((string) $config->getValue($full, ScopeInterface::SCOPE_STORE, 1)));
	    } else {
	        $value = $config->isSetFlag($full, ScopeInterface::SCOPE_STORE, 1) ? 1 : 0;
	    }

	    printf("%s=%d\n", $path, $value);
	}
PHP

step "with nothing stored, measurement is on and everything else is off"
expected=(
	flag:general/enabled=1
	flag:enforcement/shedding_enabled=0
	flag:reporting/summaries_enabled=0
	flag:reporting/totals_detail_enabled=0
	list:enforcement/disabled_observers=0
	list:enforcement/advisory_observers=0
	list:enforcement/critical_observers=0
)
requested=()
for entry in "${expected[@]}"; do
	requested+=("${entry%=*}")
done
flags="$($STORE_PROOF_PHP /app/local.d/store-proof-process-guard-flags.php "${requested[@]}")"
for entry in "${expected[@]}"; do
	path="${entry#*:}"
	path="${path%=*}"
	case "$(grep "^${path}=" <<< "$flags" || true)" in
		"${path}=${entry##*=}") : ;;
		"") bad "Magento said nothing about ${path}" ;;
		*) bad "${path} is $(grep "^${path}=" <<< "$flags" | cut -d= -f2) with nothing stored, expected ${entry##*=}" ;;
	esac
done

# --- the two console commands, with nothing stored ---------------------------

step "the policy listing runs in the storefront area"
if policies="$(magento kingletas:process-guard:policies --area=frontend)"; then
	status=0
else
	status=$?
fi
[ "$status" = "0" ] || bad "kingletas:process-guard:policies --area=frontend exited ${status}"

step "it reports measurement on and shedding off"
grep -qF 'Measurement: on   Shedding: off' <<< "$policies" \
	|| bad "the listing does not say measurement is on and shedding is off"

# The README names nine: the four order-placement events, adding to the cart,
# both totals-collection events and both product-save events.
step "it lists the nine guarded events the README names"
for event in sales_model_service_quote_submit_before sales_model_service_quote_submit_success \
	sales_order_place_after checkout_submit_all_after checkout_cart_product_add_after \
	sales_quote_collect_totals_before sales_quote_collect_totals_after \
	catalog_product_save_before catalog_product_save_after; do
	grep -qE "^${event} \([0-9]+ observer\(s\)\)$" <<< "$policies" \
		|| bad "the listing has no section for ${event}"
done
grep -qE '^[0-9]+ observer\(s\) across 9 guarded event\(s\)\.$' <<< "$policies" \
	|| bad "the listing does not total nine guarded events"

# Budgets used to live only in di.xml, and the CHANGELOG says the listing now
# prints them. These are the README's numbers.
step "it prints the budgets the README states"
budgets=(
	'| event.sales_order_place_after | 1000ms | 4000ms | none | none |'
	'| quote.collect_totals | 1500ms | none | 4 | none |'
	'| catalog.product_save | 2000ms | none | none | none |'
	'| queue.consumer | 120000ms | none | none | 768MB |'
)
squeezed="$(tr -s ' ' <<< "$policies")"
for row in "${budgets[@]}"; do
	grep -qF -- "$row" <<< "$squeezed" || bad "the listing has no budget row ${row}"
done

# Nothing classified means every observer is measured and nothing else.
step "no observer on any guarded event is classified"
classified="$(grep -cE 'never run it|contain failures|never skip or contain' <<< "$policies" || true)"
[ "$classified" = "0" ] || bad "${classified} observer row(s) are classified with nothing stored"

step "an area that does not exist is refused"
if magento kingletas:process-guard:policies --area=nowhere >/dev/null; then
	bad "kingletas:process-guard:policies accepted an area that does not exist"
fi

step "the configuration check is silent and passes"
if check="$(magento kingletas:process-guard:check)"; then
	status=0
else
	status=$?
fi
[ "$status" = "0" ] || bad "kingletas:process-guard:check exited ${status} with nothing classified"
[ -z "${check//[[:space:]]/}" ] || bad "kingletas:process-guard:check printed output with nothing wrong: ${check}"

# --- what the guard records while a cart collects its totals -----------------

# Run once per setting, because the guard reads its switches once and holds
# them for the life of the process. It reports what the journal holds and what
# was appended to the module's log while the cart collected.
cat > "$TOTALS" <<-'PHP'
	<?php
	declare(strict_types=1);

	/**
	 * Collects totals on an invented, unsaved cart and reports what the guard
	 * wrote down about it.
	 */

	require '/app/app/bootstrap.php';

	use Kingletas\ProcessGuard\Api\ProcessJournalInterface;
	use Magento\Framework\App\Area;
	use Magento\Framework\App\Bootstrap;
	use Magento\Framework\App\State;
	use Magento\Framework\Event\ConfigInterface as EventConfig;
	use Magento\Framework\ObjectManager\ConfigLoaderInterface;
	use Magento\Quote\Model\Quote;
	use Magento\Quote\Model\Quote\TotalsCollectorList;

	/**
	 * Stands in for the code that asks for a collection, so the guard has a
	 * caller of ours to name. A class without a namespace keeps its name free
	 * of backslashes wherever it is written down.
	 */
	final class StoreProofCartPage
	{
	    public function showTotals(Quote $quote): void
	    {
	        $quote->setTotalsCollectedFlag(false);
	        $quote->collectTotals();
	    }
	}

	$collections = 5;
	$event = 'sales_quote_collect_totals_before';

	$objectManager = Bootstrap::create(BP, $_SERVER)->getObjectManager();
	$objectManager->get(State::class)->setAreaCode(Area::AREA_FRONTEND);
	$objectManager->configure(
	    $objectManager->get(ConfigLoaderInterface::class)->load(Area::AREA_FRONTEND)
	);

	$observers = array_keys($objectManager->get(EventConfig::class)->getObservers($event));
	$first = $observers === [] ? '-' : (string) $observers[0];

	// What an operator reads. Only the bytes appended during this run count.
	$logDirectory = '/app/var/log/kingletas';
	$sizes = [];
	foreach (glob($logDirectory . '/process_guard*.log') ?: [] as $file) {
	    $sizes[$file] = (int) filesize($file);
	}

	$quote = $objectManager->create(Quote::class);
	$quote->setStoreId(1);
	$quote->getBillingAddress();
	$quote->getShippingAddress();

	$error = '-';
	$page = new StoreProofCartPage();
	try {
	    for ($i = 0; $i < $collections; $i++) {
	        $page->showTotals($quote);
	    }
	} catch (\Throwable $e) {
	    $error = get_class($e);
	}

	clearstatcache();
	$appended = [];
	$grown = [];
	foreach (glob($logDirectory . '/process_guard*.log') ?: [] as $file) {
	    $from = $sizes[$file] ?? 0;
	    if ((int) filesize($file) > $from) {
	        $grown[] = basename($file);
	        $text = (string) file_get_contents($file, false, null, $from);
	        $appended = array_merge($appended, preg_split('/\R/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []);
	    }
	}

	$realClass = static function (object $object): string {
	    $class = get_class($object);
	    $marker = strpos($class, '\\Interceptor');

	    return $marker === false ? $class : substr($class, 0, $marker);
	};

	// The collectors Magento runs for this store, which the breakdown has to cover.
	$expected = [];
	foreach ($objectManager->get(TotalsCollectorList::class)->getCollectors(1) as $collector) {
	    $expected[$realClass($collector)] = true;
	}

	$journal = $objectManager->get(ProcessJournalInterface::class);
	$report = $journal->getReport()->toArray();
	unset($report['_truncated']);

	$breakdown = [];
	$interceptors = 0;
	foreach (array_keys($report) as $process) {
	    if (str_starts_with((string) $process, 'totals.')) {
	        $class = substr((string) $process, strlen('totals.'));
	        $breakdown[$class] = true;
	        $interceptors += str_contains($class, 'Interceptor') ? 1 : 0;
	    }
	}

	$asked = [];
	$repeatAsked = '-';
	$killedRan = 0;
	foreach ($journal->getObservations() as $observation) {
	    $context = $observation->getContext();
	    $outcome = $observation->getOutcome()->value;

	    if ($observation->getProcess() === 'event.' . $event
	        && $observation->getLabel() === $first
	        && $outcome === 'completed'
	    ) {
	        $killedRan++;
	    }

	    if ($observation->getProcess() !== 'quote.collect_totals') {
	        continue;
	    }

	    if ($outcome === 'repeated') {
	        $repeatAsked = (string) ($context['asked_by'] ?? '-');
	    } elseif (array_key_exists('asked_by', $context)) {
	        $asked[(string) $context['asked_by']] = true;
	    }
	}

	// Lines appended during this run that carry every one of the needles.
	$count = static function (string ...$needles) use ($appended): int {
	    $found = 0;
	    foreach ($appended as $line) {
	        $all = true;
	        foreach ($needles as $needle) {
	            $all = $all && str_contains($line, $needle);
	        }
	        $found += $all ? 1 : 0;
	    }

	    return $found;
	};
	$repeatLine = sprintf('quote.collect_totals: %d calls, budget allows 4', $collections);

	printf(
	    "collections=%d error=%s calls=%d repeated=%d observers=%d first=%s gated=%d disabled=%d killedran=%d"
	    . " collectors=%d breakdown=%d missing=%d interceptors=%d asked=%s repeatasked=%s processes=%d"
	    . " logfile=%s repeatlogged=%d repeatloggedasked=%d summarised=%d collectorlogged=%d\n",
	    $collections,
	    $error,
	    $report['quote.collect_totals']['calls'] ?? 0,
	    $report['quote.collect_totals']['outcomes']['repeated'] ?? 0,
	    count($observers),
	    $first,
	    $report['event.' . $event]['outcomes']['completed'] ?? 0,
	    $report['event.' . $event]['outcomes']['disabled'] ?? 0,
	    $killedRan,
	    count($expected),
	    count($breakdown),
	    count(array_diff_key($expected, $breakdown)),
	    $interceptors,
	    $asked === [] ? '-' : implode(',', array_keys($asked)),
	    $repeatAsked,
	    count($report),
	    $grown === [] ? '-' : implode(',', $grown),
	    $count($repeatLine),
	    $count($repeatLine, 'StoreProofCartPage::showTotals'),
	    $count('quote.collect_totals finished:', 'totals.'),
	    $count('totals.')
	);
PHP

step "collecting totals ${COLLECTIONS} times with the shipped settings"
shipped="$(collect)"
step "  ${shipped}"

if ! field "$shipped" collections >/dev/null; then
	echo "    the totals script did not run to its report, so nothing about the guard can be read; its output is above" >&2
	exit 1
fi

first="$(field "$shipped" first || true)"
observers="$(field "$shipped" observers || true)"

# The kill switch and the gate are proved on an observer this store already has
# on a guarded event. Without one there is nothing to name, so say so by name
# rather than pass over it.
if [ "${observers:-0}" -lt 1 ] || [ "$first" = "-" ]; then
	echo "    this proof needs at least one observer on ${TOTALS_EVENT} in the storefront area, and this store has none" >&2
	exit 1
fi

step "an empty cart collects its totals without an error"
[ "$(field "$shipped" error)" = "-" ] \
	|| bad "collecting totals threw $(field "$shipped" error), so nothing below was measured on a working cart"

step "the guard counted every collection"
[ "$(field "$shipped" calls)" = "$COLLECTIONS" ] \
	|| bad "quote.collect_totals counted $(field "$shipped" calls) calls for ${COLLECTIONS} collections"

step "the fifth collection is reported as a repeat, once"
[ "$(field "$shipped" repeated)" = "1" ] \
	|| bad "the repeat was recorded $(field "$shipped" repeated) time(s), expected once per request"

step "the repeat reached the module's own daily log"
[ "$(field "$shipped" repeatlogged)" = "1" ] \
	|| bad "the log gained $(field "$shipped" repeatlogged) line(s) saying ${COLLECTIONS} calls against a budget of 4, expected 1"
case "$(field "$shipped" logfile)" in
	process_guard-[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9].log) : ;;
	*) bad "the report went to '$(field "$shipped" logfile)', not a dated process_guard-<date>.log" ;;
esac

step "every observer on ${TOTALS_EVENT} was timed on every collection"
[ "$(field "$shipped" gated)" = "$((observers * COLLECTIONS))" ] \
	|| bad "$(field "$shipped" gated) timed runs of ${observers} observer(s) over ${COLLECTIONS} collections"

step "with the breakdown off, no collector is timed and no caller is named"
[ "$(field "$shipped" breakdown)" = "0" ] \
	|| bad "$(field "$shipped" breakdown) collector(s) were timed with the breakdown off"
if [ "$(field "$shipped" asked)" != "-" ] || [ "$(field "$shipped" repeatasked)" != "-" ]; then
	bad "a caller was recorded with the breakdown off"
fi

step "with summaries off, no summary was logged"
[ "$(field "$shipped" summarised)" = "0" ] \
	|| bad "$(field "$shipped" summarised) summary line(s) were logged with summaries off"

# --- the breakdown, switched on ----------------------------------------------

step "collecting again with the breakdown on"
configure_guard reporting/totals_detail_enabled=1
detail="$(collect)"
step "  ${detail}"

step "each collector is timed on its own"
[ "$(field "$detail" breakdown)" -ge 1 ] 2>/dev/null \
	|| bad "no collector was timed with the breakdown on"

step "every collector Magento runs for this store is in the breakdown"
[ "$(field "$detail" missing)" = "0" ] \
	|| bad "$(field "$detail" missing) of Magento's $(field "$detail" collectors) collectors are missing from the breakdown"

# Magento serves an Interceptor subclass for every pluggable collector, and an
# operator searches the log for the class the code declares.
step "the breakdown names collectors by their declared class"
[ "$(field "$detail" interceptors)" = "0" ] \
	|| bad "$(field "$detail" interceptors) collector(s) are named by their generated interceptor"

step "every collection names the code that asked for it"
[ "$(field "$detail" asked)" = "$ASKER" ] \
	|| bad "collections were attributed to '$(field "$detail" asked)', expected ${ASKER}"

step "the repeat names who asked for the extra collection, in the log too"
[ "$(field "$detail" repeatasked)" = "$ASKER" ] \
	|| bad "the repeat was attributed to '$(field "$detail" repeatasked)', expected ${ASKER}"
[ "$(field "$detail" repeatloggedasked)" = "1" ] \
	|| bad "the logged repeat does not name ${ASKER}"

# The breakdown is kept in the journal, and what carries it to the log is the
# collection summary, which is its own switch. The CHANGELOG and the admin
# comment say so, so with summaries off no log line names a collector.
step "with summaries off, the breakdown reaches no log line"
[ "$(field "$detail" summarised)" = "0" ] \
	|| bad "$(field "$detail" summarised) summary line(s) were logged with summaries off"
[ "$(field "$detail" collectorlogged)" = "0" ] \
	|| bad "$(field "$detail" collectorlogged) log line(s) named a collector with summaries off"

step "collecting again with the breakdown and summaries on"
configure_guard reporting/summaries_enabled=1
summarised="$(collect)"
step "  ${summarised}"

step "each collection logged a summary that carries the breakdown"
[ "$(field "$summarised" summarised)" = "$COLLECTIONS" ] \
	|| bad "$(field "$summarised" summarised) summary line(s) named a collector, expected ${COLLECTIONS}"

configure_guard reporting/totals_detail_enabled= reporting/summaries_enabled=

# --- classification, through configuration ----------------------------------

# The kill list beats every other classification, so naming one observer in
# both lists must still stop it running.
step "naming ${first} as both disabled and critical"
configure_guard enforcement/disabled_observers="$first" enforcement/critical_observers="$first"

step "the listing says the kill list wins"
listing="$(magento kingletas:process-guard:policies --area=frontend || true)"
[ "$(guard_does "$first" "$listing")" = "never run it" ] \
	|| bad "the listing says the guard would '$(guard_does "$first" "$listing" | tr '\n' ' ')' to ${first}"

step "collecting with ${first} switched off"
killed="$(collect)"
step "  ${killed}"

step "it was skipped on every collection and never ran"
[ "$(field "$killed" disabled)" = "$COLLECTIONS" ] \
	|| bad "${first} was recorded as switched off $(field "$killed" disabled) time(s), expected ${COLLECTIONS}"
[ "$(field "$killed" killedran)" = "0" ] \
	|| bad "${first} ran $(field "$killed" killedran) time(s) after being switched off"

step "every other observer on ${TOTALS_EVENT} still ran"
[ "$(field "$killed" gated)" = "$(((observers - 1) * COLLECTIONS))" ] \
	|| bad "$(field "$killed" gated) timed runs of the other $((observers - 1)) observer(s), expected $(((observers - 1) * COLLECTIONS))"

[ "$(field "$killed" error)" = "-" ] \
	|| bad "collecting totals threw $(field "$killed" error) with ${first} switched off"

step "taking ${first} off the kill list leaves it critical"
configure_guard enforcement/disabled_observers=
listing="$(magento kingletas:process-guard:policies --area=frontend || true)"
[ "$(guard_does "$first" "$listing")" = "time it, never skip or contain" ] \
	|| bad "the listing says the guard would '$(guard_does "$first" "$listing" | tr '\n' ' ')' to ${first}"

step "a classification the guard can reach passes the check silently"
if check="$(magento kingletas:process-guard:check)"; then
	status=0
else
	status=$?
fi
[ "$status" = "0" ] || bad "the check exited ${status} for ${first}, which is on a guarded event"
[ -z "${check//[[:space:]]/}" ] || bad "the check printed output for a classification it can reach: ${check}"

# The defect the check exists for: a name the configuration field accepted,
# saved and listed, on no guarded event, which does nothing at all.
step "a classification the guard cannot reach fails the check by name"
configure_guard enforcement/critical_observers= enforcement/advisory_observers="$INVENTED_OBSERVER"
if check="$(magento kingletas:process-guard:check)"; then
	status=0
else
	status=$?
fi
[ "$status" = "1" ] || bad "the check exited ${status} for an observer on no guarded event, expected 1"
grep -qF "$INVENTED_OBSERVER" <<< "$check" || bad "the check did not name ${INVENTED_OBSERVER}"
grep -qF 'advisory_observers' <<< "$check" || bad "the check did not name the setting the observer came from"
grep -qF 'sales_order_place_after' <<< "$check" || bad "the check did not list the events that are watched"

configure_guard enforcement/advisory_observers=

# --- budgets a store tunes in its own di.xml ----------------------------------

# 2.0.0 took budgets as an array argument, and so does this release. Two things
# in the field hand the guard that array: a store that tuned a budget in its own
# di.xml, and a store upgrading in place from 2.0.0, whose cached wiring keeps
# the old shape until something flushes it. A build that expected an object
# there failed setup:upgrade and cache:flush on both.
#
# bin/store-proof runs the first setup:upgrade before this script starts, so
# that run is an in-place upgrade only on a store that already carried 2.0.0.
# This builds the array shape on every store instead: a module of the store's
# own whose di.xml passes budgets as an array, with one invented process and a
# looser ceiling on one that ships. setup:upgrade and cache:flush, each with
# that wiring cached, must pass, and the guard and the report must both use it.

# For the record only: which install the harness did, read from the log it
# writes under the directory it runs this script in.
if [ -f dev/var/store-proof/logs/require.log ]; then
	upgraded="$(grep -o 'Upgrading kingletas/module-process-guard ([^)]*)' dev/var/store-proof/logs/require.log | head -1 || true)"
	if [ -n "$upgraded" ]; then
		step "the harness upgraded in place, ${upgraded#Upgrading kingletas/module-process-guard }, and its setup:upgrade passed"
	else
		step "the harness installed fresh, so only the module below puts array wiring in front of setup:upgrade"
	fi
fi

step "creating ${STORE_MODULE}, whose di.xml tunes budgets as an array"
cp "$MODULE_LIST" "$MODULE_LIST_BEFORE"
[ -d "${STORE_PROOF_STORE}/app/code" ] || made_code_dir=1
store_module=1
mkdir -p "${STORE_MODULE_DIR}/etc"

cat > "${STORE_MODULE_DIR}/registration.php" <<-'PHP'
	<?php
	declare(strict_types=1);

	use Magento\Framework\Component\ComponentRegistrar;

	ComponentRegistrar::register(ComponentRegistrar::MODULE, 'StoreProof_GuardBudgets', __DIR__);
PHP

cat > "${STORE_MODULE_DIR}/etc/module.xml" <<-'XML'
	<?xml version="1.0"?>
	<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	        xsi:noNamespaceSchemaLocation="urn:magento:framework:Module/etc/module.xsd">
	    <module name="StoreProof_GuardBudgets">
	        <sequence>
	            <module name="Kingletas_ProcessGuard"/>
	        </sequence>
	    </module>
	</config>
XML

cat > "${STORE_MODULE_DIR}/etc/di.xml" <<-'XML'
	<?xml version="1.0"?>
	<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	        xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">
	    <virtualType name="StoreProof\GuardBudgets\InventedBudget" type="Kingletas\ProcessGuard\Model\Guard\Budget">
	        <arguments>
	            <argument name="warnMilliseconds" xsi:type="number">1234</argument>
	            <argument name="maxCalls" xsi:type="number">1</argument>
	        </arguments>
	    </virtualType>
	    <virtualType name="StoreProof\GuardBudgets\LooserTotalsBudget" type="Kingletas\ProcessGuard\Model\Guard\Budget">
	        <arguments>
	            <argument name="warnMilliseconds" xsi:type="number">1500</argument>
	            <argument name="maxCalls" xsi:type="number">9</argument>
	        </arguments>
	    </virtualType>
	    <type name="Kingletas\ProcessGuard\Model\Guard\ProcessGuard">
	        <arguments>
	            <argument name="budgets" xsi:type="array">
	                <item name="storeproof.invented_export" xsi:type="object">StoreProof\GuardBudgets\InventedBudget</item>
	                <item name="quote.collect_totals" xsi:type="object">StoreProof\GuardBudgets\LooserTotalsBudget</item>
	            </argument>
	        </arguments>
	    </type>
	</config>
XML

if ! out="$(magento module:enable "$STORE_MODULE")"; then
	printf '%s\n' "$out" | tail -5 | sed 's/^/      /' >&2
	bad "module:enable refused ${STORE_MODULE}"
fi

step "setup:upgrade passes with budgets wired as an array"
if ! out="$(magento setup:upgrade)"; then
	printf '%s\n' "$out" | tail -5 | sed 's/^/      /' >&2
	bad "setup:upgrade failed with ${STORE_MODULE}'s budgets in place"
fi

step "the report prints the store's budgets over the shipped ones"
listing="$(magento kingletas:process-guard:policies || true)"
squeezed="$(tr -s ' ' <<< "$listing")"
for row in \
	"| ${INVENTED_PROCESS} | 1234ms | none | 1 | none |" \
	'| quote.collect_totals | 1500ms | none | 9 | none |' \
	'| queue.consumer | 120000ms | none | none | 768MB |'; do
	grep -qF -- "$row" <<< "$squeezed" || bad "the listing has no budget row ${row}"
done

# The listing above has just built the guard, so the wiring is cached, as it is
# on a store running 2.0.0 the moment before its upgrade.
step "setup:upgrade passes again with that wiring cached"
if ! out="$(magento setup:upgrade)"; then
	printf '%s\n' "$out" | tail -5 | sed 's/^/      /' >&2
	bad "setup:upgrade failed with ${STORE_MODULE}'s budgets cached"
fi

magento kingletas:process-guard:policies >/dev/null || true
step "cache:flush passes with that wiring cached"
magento cache:flush >/dev/null || bad "cache:flush failed with ${STORE_MODULE}'s budgets cached"

cat > "$BUDGETS" <<-'PHP'
	<?php
	declare(strict_types=1);

	/**
	 * Runs two processes through the guard Magento builds and reports how often
	 * each was reported as a repeat.
	 */

	require '/app/app/bootstrap.php';

	use Kingletas\ProcessGuard\Api\ProcessGuardInterface;
	use Kingletas\ProcessGuard\Api\ProcessJournalInterface;
	use Magento\Framework\App\Bootstrap;

	$objectManager = Bootstrap::create(BP, $_SERVER)->getObjectManager();
	$guard = $objectManager->get(ProcessGuardInterface::class);

	// Past the invented ceiling of one, and five times under the store's nine,
	// where the shipped ceiling of four would report a repeat.
	for ($i = 0; $i < 2; $i++) {
	    $guard->run('storeproof.invented_export', static fn (): bool => true);
	}
	for ($i = 0; $i < 5; $i++) {
	    $guard->run('quote.collect_totals', static fn (): bool => true);
	}

	$report = $objectManager->get(ProcessJournalInterface::class)->getReport()->toArray();

	printf(
	    "invented=%d totals=%d\n",
	    $report['storeproof.invented_export']['outcomes']['repeated'] ?? 0,
	    $report['quote.collect_totals']['outcomes']['repeated'] ?? 0
	);
PHP

step "the guard judges by the store's budgets"
judged="$($STORE_PROOF_PHP /app/local.d/store-proof-process-guard-budgets.php 2>&1 | tail -1)"
step "  ${judged}"
[ "$(field "$judged" invented)" = "1" ] \
	|| bad "the invented process was reported as a repeat $(field "$judged" invented) time(s) past a ceiling of one, expected once"
[ "$(field "$judged" totals)" = "0" ] \
	|| bad "five collections were reported as a repeat under the store's ceiling of nine, so the shipped budget won"

step "taking ${STORE_MODULE} out again"
remove_store_module || bad "${STORE_MODULE} could not be taken out"
store_module=0
magento cache:flush >/dev/null || true

step "the module list is exactly as it was, and the module is gone"
cmp -s "$MODULE_LIST_BEFORE" "$MODULE_LIST" || bad "app/etc/config.php differs from the copy taken before ${STORE_MODULE} was enabled"
[ ! -e "$STORE_VENDOR_DIR" ] || bad "${STORE_VENDOR_DIR#"${STORE_PROOF_STORE}"/} is still there"
left="$(value "SELECT COUNT(*) FROM setup_module WHERE module = '${STORE_MODULE}';")"
[ "$left" = "0" ] || bad "setup_module still has a row for ${STORE_MODULE}"
rm -f "$MODULE_LIST_BEFORE"

step "the report is back to the shipped budgets"
listing="$(magento kingletas:process-guard:policies || true)"
squeezed="$(tr -s ' ' <<< "$listing")"
if grep -qF "| ${INVENTED_PROCESS} |" <<< "$squeezed"; then
	bad "the listing still has ${INVENTED_PROCESS} after ${STORE_MODULE} was taken out"
fi
grep -qF -- '| quote.collect_totals | 1500ms | none | 4 | none |' <<< "$squeezed" \
	|| bad "quote.collect_totals is not back to the shipped ceiling of four"

# --- measurement, switched off -----------------------------------------------

step "collecting with measurement off"
configure_guard general/enabled=0
off="$(collect)"
step "  ${off}"

step "nothing is recorded and nothing is logged"
[ "$(field "$off" processes)" = "0" ] \
	|| bad "$(field "$off" processes) process(es) were recorded with measurement off"
[ "$(field "$off" repeatlogged)" = "0" ] \
	|| bad "the repeat was logged with measurement off"

step "totals are still collected, straight through"
[ "$(field "$off" error)" = "-" ] \
	|| bad "collecting totals threw $(field "$off" error) with measurement off"

step "the listing says the guard is switched off"
listing="$(magento kingletas:process-guard:policies || true)"
grep -qF 'The guard is switched off' <<< "$listing" \
	|| bad "the listing does not say the guard is switched off"

# --- the store's own settings, put back --------------------------------------

step "putting the store's settings back"
restore_config || bad "the captured settings could not be put back"
magento cache:flush >/dev/null || true

step "every row the section held came back with its value, and no other row is left"
[ "$(section_fingerprint)" = "$before" ] \
	|| bad "the section's rows differ from the ones captured at the start"

step "the invented row came back at its own scope, backslashes intact"
[ "$(planted_rows)" = "1" ] || bad "${PLANTED_PATH} did not come back with its value"

step "each path the proof wrote holds as many rows as it did before"
for path in "${WRITTEN[@]}"; do
	after="$(path_rows "$path")"
	[ "$after" = "${rows_before[$path]}" ] \
		|| bad "${SECTION}/${path} had ${rows_before[$path]} row(s) and now has ${after}"
done

# Put back and checked, so the exit has nothing left to restore.
captured=0
step "the invented row is removed again"
remove_planted || bad "the invented ${PLANTED_PATH} could not be removed"
left="$(value "SELECT COUNT(*) FROM core_config_data WHERE path = '${PLANTED_PATH}';")"
[ "$left" = "0" ] || bad "${left} invented ${PLANTED_PATH} row(s) are still there"
planted=0

# --- verdict -----------------------------------------------------------------

if [ "$failures" -gt 0 ]; then
	printf '\n    %d assertion(s) failed\n' "$failures" >&2
	exit 1
fi

printf '    every assertion held\n'
