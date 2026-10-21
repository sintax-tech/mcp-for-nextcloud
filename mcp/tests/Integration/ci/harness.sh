#!/bin/sh
# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Disposable Nextcloud instance for the integration suite (.github/workflows/integration.yml).
# It starts containers, so it refuses to run anywhere but a GitHub Actions runner or an explicit local run
# (MCP_IT_LOCAL=1, set by run-local.sh, which only cleans up its own labeled resources).
#
# Usage: harness.sh <packages|tools|start-db|start-nextcloud|install-app|fixtures|provision|report|test|recovery|collect>
# Cell, from the workflow matrix:
#   MCP_IT_NC          Nextcloud major (32..35)
#   MCP_IT_NC_IMAGE    official Nextcloud Apache image, pinned as tag@digest
#   MCP_IT_DB          mariadb | pgsql | sqlite
#   MCP_IT_DB_IMAGE    database image, pinned as tag@digest (empty for sqlite)
#   MCP_IT_MODE        fresh (install the branch) | upgrade (install the baseline release, then upgrade to the branch)
#   MCP_IT_BASELINE    git tag of the released baseline for the upgrade mode
set -eu

if { [ "${GITHUB_ACTIONS:-}" != "true" ] && [ "${MCP_IT_LOCAL:-}" != "1" ]; } || [ -z "${RUNNER_TEMP:-}" ]; then
    echo "harness.sh starts Nextcloud and database containers; it runs only on a GitHub Actions runner or through run-local.sh." >&2
    exit 1
fi

# Checks "<sha256>  <file>" on stdin: GNU coreutils on the runner, the BSD tools on a Mac.
sha256_check() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum -c -
    else
        shasum -a 256 -c -
    fi
}

REPO_ROOT=$(cd "$(dirname "$0")/../../../.." && pwd)
WORK="$RUNNER_TEMP/mcp-it"
TOOLS_DIR="$WORK/tools"
PG_LOG_DIR="$WORK/pglog"
ARTIFACTS="$WORK/artifacts"
# Names of this run's network and containers; run-local.sh gives each run its own so that two runs on one engine never touch each other.
NET=${MCP_IT_NET:?}
NC=${MCP_IT_NC_NAME:?}
DB=${MCP_IT_DB_NAME:?}
: "${MCP_IT_RUN_ID:?}" "${MCP_IT_NC_IMAGE:?}"
# Random disposable credentials remain outside the artifact directory and are redacted before output.
mkdir -p "$WORK"
if [ ! -f "$WORK/credentials" ]; then
    (umask 077; for key in DB_PASSWORD DB_ROOT_PASSWORD ADMIN_PASSWORD; do
        printf '%s=%s\n' "$key" "$(openssl rand -hex 24)"
    done > "$WORK/credentials")
fi
# shellcheck source=/dev/null
. "$WORK/credentials"
# Real mounts of the exclusion proof (issue #4, A4): the groupfolder and the three local external storages the
# tests rely on (tests/Integration/Exclusion/ExclusionTestCase.php). Both external aliases point at the same directory; the
# narrow one at a subdirectory of it.
GF_NAME=mcp-it-gf
EXT_ROOT=/mcp-it-ext
PHPUNIT_VERSION=10.5.65
PHPUNIT_SHA256=ce85745a2ec7d8e536621ebed69dfa36a8d504daf73be6750e93bb8c5b088835

mkdir -p "$WORK" "$TOOLS_DIR" "$ARTIFACTS"
chmod 0755 "$TOOLS_DIR"
cp "$RUNNER_TEMP/plan.json" "$ARTIFACTS/plan.json"
git -C "$REPO_ROOT" rev-parse HEAD > "$ARTIFACTS/source-commit.txt"
if [ -n "${MCP_IT_PHPUNIT_ARGS:-}" ]; then
    printf 'Filtered diagnostic run only: %s\n' "$MCP_IT_PHPUNIT_ARGS" > "$ARTIFACTS/diagnostic.txt"
fi

occ() {
    docker exec -u www-data "$NC" php /var/www/html/occ "$@"
}

# wait_for <label> <seconds> <command...>: retries the command every 2 s until it succeeds.
wait_for() {
    label=$1
    deadline=$(($(date +%s) + $2))
    shift 2
    until "$@" >/dev/null 2>&1; do
        if [ "$(date +%s)" -ge "$deadline" ]; then
            echo "Timed out waiting for $label" >&2
            return 1
        fi
        sleep 2
    done
    echo "$label is ready"
}

info_version() {
    sed -n 's:.*<version>\(.*\)</version>.*:\1:p' "$1"
}

# Builds the branch package, and the baseline package for the upgrade mode, each with its own official script.
cmd_packages() {
    (cd "$REPO_ROOT/mcp" && npm ci --ignore-scripts)
    build_package "$REPO_ROOT/mcp" "$WORK/mcp-branch.tar.gz"
    if [ -n "$MCP_IT_BASELINE" ]; then
        # Clone, never a linked worktree: the server's shared worktree registry is not modified.
        git clone --no-hardlinks --no-checkout "$REPO_ROOT" "$WORK/baseline"
        if ! git -C "$WORK/baseline" cat-file -e "$MCP_IT_BASELINE^{commit}"; then
            git -C "$WORK/baseline" fetch https://github.com/sintax-tech/mcp-for-nextcloud.git "$MCP_IT_BASELINE"
        fi
        git -C "$WORK/baseline" checkout --detach "$MCP_IT_BASELINE"
        if [ "$MCP_IT_MODE" = upgrade-local ]; then
            (cd "$WORK/baseline/mcp" && npm ci --ignore-scripts)
        fi
        build_package "$WORK/baseline/mcp" "$WORK/mcp-baseline.tar.gz"
    fi
    sha256sum "$WORK"/mcp-*.tar.gz > "$ARTIFACTS/packages.sha256"
}

# build_package <app dir> <destination>: the script's last output line is the archive path. Its output goes through
# a file, not a pipe, so a failing package.sh fails the step.
build_package() {
    (cd "$1" && if [ "$1" = "$REPO_ROOT/mcp" ] || [ "$MCP_IT_MODE" = upgrade-local ]; then composer package; else sh scripts/package.sh; fi) > "$WORK/package.out"
    cat "$WORK/package.out"
    archive=$(tail -n 1 "$WORK/package.out")
    cp "$1/$archive" "$2"
}

# PHPUnit runs inside the Nextcloud container as a PHAR, so none of its dependencies shadow the server's own.
cmd_tools() {
    curl -fsSL -o "$TOOLS_DIR/phpunit.phar" "https://phar.phpunit.de/phpunit-$PHPUNIT_VERSION.phar"
    echo "$PHPUNIT_SHA256  $TOOLS_DIR/phpunit.phar" | sha256_check
    chmod 0644 "$TOOLS_DIR/phpunit.phar"
}

cmd_start_db() {
    subnet=$(php "$REPO_ROOT/mcp/tests/Integration/ci/network.php")
    printf '%s\n' "$subnet" > "$ARTIFACTS/subnet.txt"
    docker network create --subnet "$subnet" --label "mcp.integration.run=$MCP_IT_RUN_ID" "$NET" >/dev/null
    case "$MCP_IT_DB" in
        mariadb)
            docker run -d --label "mcp.integration.run=$MCP_IT_RUN_ID" --name "$DB" --network "$NET" \
                -e MARIADB_ROOT_PASSWORD="$DB_ROOT_PASSWORD" -e MARIADB_DATABASE=nextcloud \
                -e MARIADB_USER=nextcloud -e MARIADB_PASSWORD="$DB_PASSWORD" \
                "$MCP_IT_DB_IMAGE" --transaction-isolation=READ-COMMITTED --log-bin=binlog --binlog-format=ROW >/dev/null
            # TCP only answers once the init phase (socket only) is over.
            wait_for MariaDB 180 docker exec "$DB" mariadb -h 127.0.0.1 -u nextcloud -p"$DB_PASSWORD" -e 'SELECT 1' nextcloud
            ;;
        pgsql)
            # Every statement of every session goes to a file the Nextcloud container can read, with the backend
            # pid in each line, so a test can attribute statements to the caller's session (contracts §3.3.6, F5).
            mkdir -p "$PG_LOG_DIR"
            chmod 0777 "$PG_LOG_DIR"
            docker run -d --label "mcp.integration.run=$MCP_IT_RUN_ID" --name "$DB" --network "$NET" \
                -e POSTGRES_DB=nextcloud -e POSTGRES_USER=nextcloud -e POSTGRES_PASSWORD="$DB_PASSWORD" \
                -v "$PG_LOG_DIR:/var/log/mcp-it-pg" \
                "$MCP_IT_DB_IMAGE" \
                -c logging_collector=on -c log_directory=/var/log/mcp-it-pg -c log_filename=postgresql.log \
                -c log_file_mode=0644 -c log_rotation_age=0 -c log_rotation_size=0 \
                -c log_statement=all -c log_min_error_statement=error \
                -c 'log_line_prefix=%m mcp-it[%p] xid=%x ' >/dev/null
            wait_for PostgreSQL 180 docker exec "$DB" pg_isready -h 127.0.0.1 -U nextcloud -d nextcloud
            ;;
        sqlite)
            ;;
        *)
            echo "Unknown database: $MCP_IT_DB" >&2
            exit 1
            ;;
    esac
}

nextcloud_ready() {
    occ status --output=json | grep -q '"installed":true' \
        && docker exec "$NC" php -r 'exit(@file_get_contents("http://localhost/status.php") === false ? 1 : 0);'
}

# The official image installs Nextcloud itself from these variables (docker-entrypoint.sh) and then starts Apache.
cmd_start_nextcloud() {
    # Copy only native test inputs; make that immutable snapshot readable by the container user.
    mkdir -p "$WORK/test-source/mcp/tests/Support/Access/Policy"
    cp -R "$REPO_ROOT/mcp/tests/Integration" "$WORK/test-source/mcp/tests/Integration"
    cp "$REPO_ROOT/mcp/tests/Support/Access/Policy/PolicyServices.php" "$WORK/test-source/mcp/tests/Support/Access/Policy/"
    find "$WORK/test-source" -type d -exec chmod 0755 {} +
    find "$WORK/test-source" -type f -exec chmod 0644 {} +
    tar -C "$WORK/test-source" -cf "$WORK/native-test-source.tar" .
    sha256sum "$WORK/native-test-source.tar" > "$ARTIFACTS/test-source.sha256"
    set -- -e NEXTCLOUD_ADMIN_USER=admin -e NEXTCLOUD_ADMIN_PASSWORD="$ADMIN_PASSWORD"
    case "$MCP_IT_DB" in
        mariadb)
            set -- "$@" -e MYSQL_HOST="$DB" -e MYSQL_DATABASE=nextcloud -e MYSQL_USER=nextcloud -e MYSQL_PASSWORD="$DB_PASSWORD"
            ;;
        pgsql)
            set -- "$@" -e POSTGRES_HOST="$DB" -e POSTGRES_DB=nextcloud -e POSTGRES_USER=nextcloud \
                -e POSTGRES_PASSWORD="$DB_PASSWORD" -v "$PG_LOG_DIR:/mcp-it-pglog:ro"
            ;;
        sqlite)
            # One database file under the data directory; both test connections open this same file.
            set -- "$@" -e SQLITE_DATABASE=nextcloud
            ;;
    esac
    docker run -d --label "mcp.integration.run=$MCP_IT_RUN_ID" --name "$NC" --network "$NET" "$@" \
        -v "$WORK/test-source:/mcp-it-src:ro" -v "$TOOLS_DIR:/mcp-it-tools:ro" \
        "$MCP_IT_NC_IMAGE" >/dev/null
    if ! wait_for "Nextcloud $MCP_IT_NC" 600 nextcloud_ready; then
        docker logs "$NC" >&2 || true
        exit 1
    fi
}

assert_installed_version() {
    actual=$(occ config:app:get mcp installed_version)
    if [ "$actual" != "$1" ]; then
        echo "Expected mcp $1 to be installed, found '$actual'" >&2
        exit 1
    fi
    occ app:list --enabled | grep -q -- '- mcp:' || { echo "mcp is not enabled" >&2; exit 1; }
    echo "mcp $actual is installed and enabled"
}

# Installs through the server's own installer (app:enable runs the app's migrations); upgrade goes through occ upgrade.
cmd_install_app() {
    if [ -n "$MCP_IT_BASELINE" ]; then
        mkdir -p "$WORK/baseline-pkg"
        tar -xzf "$WORK/mcp-baseline.tar.gz" -C "$WORK/baseline-pkg"
        baseline_version=$(info_version "$WORK/baseline-pkg/mcp/appinfo/info.xml")
        docker cp "$WORK/mcp-baseline.tar.gz" "$NC:/tmp/mcp-baseline.tar.gz"
        docker exec "$NC" chmod 0644 /tmp/mcp-baseline.tar.gz
        docker exec -u www-data "$NC" tar -xzf /tmp/mcp-baseline.tar.gz -C /var/www/html/custom_apps
        occ app:enable mcp
        assert_installed_version "$baseline_version"
        if [ "$MCP_IT_MODE" != replace-upstream ] && [ "$MCP_IT_MODE" != upgrade-upstream ] && [ "$MCP_IT_MODE" != upgrade-upstream-111 ]; then
            docker exec -u www-data "$NC" php /mcp-it-src/mcp/tests/Integration/ci/legacy-credentials.php /tmp/mcp-it-legacy.json
            docker exec -u www-data -e MCP_IT_MODE="$MCP_IT_MODE" "$NC" php /mcp-it-src/mcp/tests/Integration/ci/legacy-policy.php seed /tmp/mcp-it-snapshot.json
        else
            docker exec -u www-data "$NC" php /mcp-it-src/mcp/tests/Integration/ci/replacement.php seed
        fi
    fi
    mkdir -p "$WORK/branch-pkg"
    tar -xzf "$WORK/mcp-branch.tar.gz" -C "$WORK/branch-pkg"
    expected=$(info_version "$WORK/branch-pkg/mcp/appinfo/info.xml")
    if [ -n "${MCP_IT_QUALIFICATION_VERSION:-}" ]; then
        [ "$MCP_IT_QUALIFICATION_VERSION" = 1.1.0 ] || { echo 'Only explicit 1.1.0 harness qualification is supported' >&2; exit 1; }
        printf 'HARNESS QUALIFICATION ONLY: candidate copy %s -> %s; NOT final-artifact evidence\n' "$expected" "$MCP_IT_QUALIFICATION_VERSION" | tee "$ARTIFACTS/qualification.txt"
        expected=$MCP_IT_QUALIFICATION_VERSION
        sed "s:<version>.*</version>:<version>$expected</version>:" "$WORK/branch-pkg/mcp/appinfo/info.xml" > "$WORK/info.xml.next"
        mv "$WORK/info.xml.next" "$WORK/branch-pkg/mcp/appinfo/info.xml"
    fi
    if [ -n "$MCP_IT_BASELINE" ]; then
        if [ "$MCP_IT_MODE" = replace-upstream ]; then
            [ "$expected" = "$baseline_version" ] || { echo 'Replacement requires identical declared versions' >&2; exit 1; }
        else
            # shellcheck disable=SC2016
            php -r 'exit(version_compare($argv[1], $argv[2], ">") ? 0 : 1);' "$expected" "$baseline_version" || {
                echo 'Upgrade needs a newer candidate version; explicit MCP_IT_QUALIFICATION_VERSION=1.1.0 is diagnostic only' >&2; exit 1;
            }
        fi
        occ maintenance:mode --on
        docker cp "$WORK/branch-pkg/mcp" "$NC:/tmp/mcp-next"
        docker exec "$NC" sh -c 'mv /var/www/html/custom_apps/mcp /tmp/mcp-previous && mv /tmp/mcp-next /var/www/html/custom_apps/mcp && chown -R www-data:www-data /var/www/html/custom_apps/mcp'
        if [ "$MCP_IT_MODE" != replace-upstream ]; then occ upgrade; fi
        # A new Apache process removes old opcache/APCu without faking an upgrade.
        docker restart "$NC" >/dev/null
        wait_for 'Nextcloud after replacement' 180 nextcloud_ready
        occ maintenance:mode --off
        if [ "$MCP_IT_MODE" = replace-upstream ] || [ "$MCP_IT_MODE" = upgrade-upstream ] || [ "$MCP_IT_MODE" = upgrade-upstream-111 ]; then
            docker exec -u www-data "$NC" php /mcp-it-src/mcp/tests/Integration/ci/replacement.php verify
        fi
    else
        docker cp "$WORK/branch-pkg/mcp" "$NC:/tmp/mcp-new"
        docker exec "$NC" sh -c 'mv /tmp/mcp-new /var/www/html/custom_apps/mcp && chown -R www-data:www-data /var/www/html/custom_apps/mcp'
        occ app:enable mcp
    fi
    assert_installed_version "$expected"
    docker exec -u www-data "$NC" php /mcp-it-src/mcp/tests/Integration/ci/schema.php > "$ARTIFACTS/migrations.json"
    echo "$expected" > "$WORK/expected-version"
}

# Fixture accounts the integration tests rely on (tests/Integration/Fixtures.php).
cmd_fixtures() {
    # Only on this throwaway instance: the default password_policy app would check the fixture passwords against
    # the external HIBP service, which makes this step depend on the network. Nothing in the suite relies on it.
    enabled=$(occ app:list --enabled)
    case "$enabled" in
        *"- password_policy:"*) occ app:disable password_policy ;;
    esac
    password=$(openssl rand -hex 24)
    for uid in mcp-it-alice mcp-it-bob mcp-it-legacy; do
        docker exec -u www-data -e OC_PASS="$password" "$NC" php /var/www/html/occ user:add --password-from-env "$uid"
    done
    if [ "$MCP_IT_MODE" = upgrade ] || [ "$MCP_IT_MODE" = upgrade-local ]; then
        # Owners of the snapshot the baseline wrote before the upgrade (Compat/SnapshotProbe::uids).
        for i in 0 1 2 3 4 5 6 7 8; do
            docker exec -u www-data -e OC_PASS="$password" "$NC" php /var/www/html/occ user:add --password-from-env "mcp-it-snap-$i"
        done
    fi
    occ group:add mcp-it-staff
    occ group:add mcp-it-empty
    occ group:adduser mcp-it-staff mcp-it-alice
}

# Downloads a release archive from the project's release repository, checks it against the pinned checksum and
# unpacks it into custom_apps, then enables the app through the server. The archive is read inside the container
# from the read-only tools mount, so nothing else is copied.
install_release_app() {
    app=$1
    unpack_release_app "$@"
    occ app:enable "$app"
}

# unpack_release_app <app> <repo> <tag> <sha256>: the download, check and unpacking of install_release_app, without
# enabling the app.
unpack_release_app() {
    app=$1
    repo=$2
    tag=$3
    sha=$4
    archive="$TOOLS_DIR/$app.tar.gz"
    curl -fsSL -o "$archive" "https://github.com/nextcloud-releases/$repo/releases/download/$tag/$app-$tag.tar.gz"
    echo "$sha  $archive" | sha256_check
    chmod 0644 "$archive"
    docker exec -u www-data "$NC" tar -xzf "/mcp-it-tools/$app.tar.gz" -C /var/www/html/custom_apps
}

# Release pinned per Nextcloud major: groupfolders, Talk and Notes each have one build per supported major. The Calendar
# release below declares min-version 32 and max-version 35, so one build serves every major this matrix covers.
pin_releases() {
    case "$MCP_IT_NC" in
        32)
            GF_TAG=v20.1.19; GF_SHA=e59e6a11a50f60bf3bd23c243b7ec4b01af371c89e4ad65d4ec7e788058f50ff
            TALK_TAG=v22.0.19; TALK_SHA=c053dbce9c2a4a76c42e937d4d095502ba48a38c2e6fa02d46c36762573d6bff
            NOTES_TAG=v5.0.2; NOTES_SHA=1bf9e97a92bae5901a17c93666c4ac0f36ddfe68c69caa5d2368b18be6af7fdc
            CAL_TAG=v6.6.2; CAL_SHA=7e83632d4436d3037a34d1c73cbc06d5ccb6e8fc10f096a86a43a0515588529c
            ;;
        33)
            GF_TAG=v21.0.15; GF_SHA=1ecdd644a2e913de8b333560717660f0ba75836309c4463af858e886cdc3a68c
            TALK_TAG=v23.0.12; TALK_SHA=a907070cb8c0eb69a0ef808f62ea6eea822365c97a1c0e6f4a86aa748eda6c79
            NOTES_TAG=v6.0.2; NOTES_SHA=da362fb5a574b45cc0e10f4ea7f81547cc3dd10bf916f9d9b590358c1ed8e101
            CAL_TAG=v6.6.2; CAL_SHA=7e83632d4436d3037a34d1c73cbc06d5ccb6e8fc10f096a86a43a0515588529c
            ;;
        34)
            GF_TAG=v22.0.6; GF_SHA=bfff357b12bbd24257d8d127cf30e83a72256e7659f1cc3e32bd09fe647d2f9b
            TALK_TAG=v24.0.6; TALK_SHA=4a8d5cf52ed42223ea9937860f70c2e9dca1df8bfd5f75b871ae7569d2106110
            NOTES_TAG=v6.0.2; NOTES_SHA=da362fb5a574b45cc0e10f4ea7f81547cc3dd10bf916f9d9b590358c1ed8e101
            CAL_TAG=v6.6.2; CAL_SHA=7e83632d4436d3037a34d1c73cbc06d5ccb6e8fc10f096a86a43a0515588529c
            ;;
        35)
            GF_TAG=v23.0.1; GF_SHA=3949e6a45947188090c170c6983aae11a1d66134ec317ffe7b7609062f3aecf3
            TALK_TAG=v25.0.6; TALK_SHA=2b3e3eae42d9bf8ba8da2ab244d16347a3cfacbb8bff346bd99cf32cd6f9c65e
            NOTES_TAG=v6.0.2; NOTES_SHA=da362fb5a574b45cc0e10f4ea7f81547cc3dd10bf916f9d9b590358c1ed8e101
            CAL_TAG=v6.6.2; CAL_SHA=7e83632d4436d3037a34d1c73cbc06d5ccb6e8fc10f096a86a43a0515588529c
            ;;
        *)
            echo "No pinned releases for Nextcloud $MCP_IT_NC" >&2
            return 1
            ;;
    esac
    # Guests v4.10.1 declares min-version 32 and max-version 36: one build for the whole matrix.
    GUESTS_TAG=v4.10.1; GUESTS_SHA=731944e1c881afe9821146974aa48bca9d9bff4249ad774b6bbdc50a48068ba7
}

provision_groupfolders() {
    install_release_app groupfolders groupfolders "$GF_TAG" "$GF_SHA"
    # The last line of the command is the new folder's id.
    folder_id=$(occ groupfolders:create "$GF_NAME" | tail -n 1)
    case "$folder_id" in
        ''|*[!0-9]*) echo "groupfolders:create gave no folder id: '$folder_id'" >&2; return 1 ;;
    esac
    occ groupfolders:group "$folder_id" mcp-it-staff read write share delete
    occ groupfolders:list
}

# One global mount per name, applicable to both fixture users. "alias" reaches the same directory as "wide"; "narrow"
# a subdirectory of it, which Nextcloud treats as a separate storage.
provision_files_external() {
    occ app:enable files_external
    occ config:system:set files_external_allow_create_new_local --value=true --type=boolean
    docker exec -u root "$NC" sh -c "mkdir -p '$EXT_ROOT/Open' && chown -R www-data:www-data '$EXT_ROOT' && chmod -R 0770 '$EXT_ROOT'"
    for spec in "wide:$EXT_ROOT" "alias:$EXT_ROOT" "narrow:$EXT_ROOT/Open"; do
        name=${spec%%:*}
        dir=${spec#*:}
        mount_id=$(occ files_external:create --output=json "/mcp-it-ext-$name" local null::null -c "datadir=$dir" | tail -n 1)
        case "$mount_id" in
            ''|*[!0-9]*) echo "files_external:create gave no mount id for $name: '$mount_id'" >&2; return 1 ;;
        esac
        occ files_external:applicable "$mount_id" --add-user=mcp-it-alice --add-user=mcp-it-bob
        occ files_external:option "$mount_id" filesystem_check_changes 1
    done
    occ files_external:list
}

# Each part is attempted on its own and a failure is recorded, never fatal here: the suite then fails by name at
# the scenario that needs the part (Exclusion/ProvisioningTest), with this log next to it in the uploaded artifact.
cmd_provision() {
    pin_releases
    # Core keeps the app config in the web server's local cache (APCu) for 3 seconds (AppConfig::LOCAL_CACHE_TTL, NC 32 to
    # 35) and invalidates it only for writes made through that same cache; the PHP CLI that writes the settings in this
    # suite has no such cache. Most scenarios change the exclusion list and read it back at once, so the cache is off
    # here. Its real window is measured, with the cache on, by Exclusion/AppConfigCacheWindowTest.
    occ config:system:set cache_app_config --value=false --type=boolean
    log="$ARTIFACTS/provision.log"
    : > "$log"
    : > "$WORK/provision-failures"
    for part in groupfolders files_external talk notes calendar guests; do
        echo "=== provisioning $part" | tee -a "$log"
        case "$part" in
            groupfolders) step=provision_groupfolders ;;
            files_external) step=provision_files_external ;;
            talk) step="install_release_app spreed spreed $TALK_TAG $TALK_SHA" ;;
            notes) step="install_release_app notes notes $NOTES_TAG $NOTES_SHA" ;;
            calendar) step="install_release_app calendar calendar $CAL_TAG $CAL_SHA" ;;
            # Unpacked only: Guests/GuestRestrictionsTest enables it for its own scenarios, since it adds a user and a
            # group backend every other test would see.
            guests) step="unpack_release_app guests guests $GUESTS_TAG $GUESTS_SHA" ;;
        esac
        # Not inside an if or ||: there errexit would be ignored in the subshell and a failing command would not
        # fail the part.
        set +e
        (set -e; $step) >> "$log" 2>&1
        status=$?
        set -e
        if [ "$status" -eq 0 ]; then
            echo "$part: provisioned" | tee -a "$log"
        else
            echo "$part: FAILED (exit $status)" | tee -a "$log"
            echo "$part" >> "$WORK/provision-failures"
        fi
    done
    occ app:list --enabled >> "$log" 2>&1 || true
    cat "$log"
    [ ! -s "$WORK/provision-failures" ] || { echo "Required app provisioning failed" >&2; return 1; }
}

db_version() {
    case "$MCP_IT_DB" in
        mariadb) docker exec "$DB" mariadb -h 127.0.0.1 -u root -p"$DB_ROOT_PASSWORD" -N -e 'SELECT VERSION()' ;;
        pgsql) docker exec "$DB" psql -U nextcloud -d nextcloud -tAc 'SELECT version()' ;;
        sqlite) docker exec "$NC" php -r 'echo "SQLite ", SQLite3::version()["versionString"], PHP_EOL;' ;;
    esac
}

image_digests() {
    docker image inspect --format '{{join .RepoDigests ", "}}' "$1"
}

# Records what actually ran: image digests, PHP, Nextcloud and database versions, app version. Each value is
# captured by its own assignment, so a failing command fails the step (set -e) before anything is published,
# instead of leaving a green step with a partial summary. The report is also kept with the uploaded logs.
cmd_report() {
    nc_digests=$(image_digests "$MCP_IT_NC_IMAGE")
    db_digests=none
    if [ -n "${MCP_IT_DB_IMAGE:-}" ]; then
        db_digests=$(image_digests "$MCP_IT_DB_IMAGE")
    fi
    database=$(db_version)
    php_version=$(docker exec "$NC" php -v)
    status=$(occ status)
    installed=$(occ config:app:get mcp installed_version)
    expected=$(cat "$WORK/expected-version")
    fence='```'
    report="$ARTIFACTS/runtime.md"
    cat > "$report" <<REPORT
### NC $MCP_IT_NC · $MCP_IT_DB · $MCP_IT_MODE
$fence
Nextcloud image: $nc_digests
Database image: $db_digests
Database: $database
$php_version
$status
mcp installed_version: $installed (expected $expected)
$fence
REPORT
    cat "$report"
    if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
        cat "$report" >> "$GITHUB_STEP_SUMMARY"
    fi
}

cmd_test() {
    expected=$(cat "$WORK/expected-version")
    set -- -e NEXTCLOUD_ROOT=/var/www/html -e MCP_IT_NC="$MCP_IT_NC" -e MCP_IT_DB="$MCP_IT_DB" \
        -e MCP_IT_MODE="$MCP_IT_MODE" -e MCP_IT_EXPECTED_VERSION="$expected" -e MCP_IT_LEGACY=/tmp/mcp-it-legacy.json \
        -e MCP_IT_SNAPSHOT=/tmp/mcp-it-snapshot.json -e MCP_IT_BASELINE_APP=/tmp/mcp-previous \
        -e MCP_IT_PROVISION_FAILURES="$(tr '\n' ' ' < "$WORK/provision-failures" 2>/dev/null || true)" \
        -e MCP_IT_GF_NAME="$GF_NAME"
    if [ "$MCP_IT_DB" = pgsql ]; then
        set -- "$@" -e MCP_IT_PG_LOG=/mcp-it-pglog/postgresql.log
    fi
    # MCP_IT_PHPUNIT_ARGS (local diagnostics only), e.g. "--filter SomeTest", is passed to PHPUnit as is.
    # shellcheck disable=SC2086
    docker exec -u www-data "$@" "$NC" php -d memory_limit=1G /mcp-it-tools/phpunit.phar \
        -c /mcp-it-src/mcp/tests/Integration/phpunit.xml --fail-on-empty-test-suite --log-junit /tmp/mcp-it-junit.xml ${MCP_IT_PHPUNIT_ARGS:-}
}

# One full coordinated restore rehearsal, isolated from the policy tests and never against a real installation.
cmd_recovery() {
    if [ "$MCP_IT_NC:$MCP_IT_DB:$MCP_IT_MODE" != 35:sqlite:fresh ]; then
        echo 'Coordinated recovery is covered by the NC35/SQLite/fresh cell.'
        return
    fi
    docker exec -u www-data "$NC" php /mcp-it-src/mcp/tests/Integration/ci/recovery.php seed
    occ maintenance:mode --on
    docker exec -u www-data "$NC" php /mcp-it-src/mcp/tests/Integration/ci/sqlite-copy.php backup
    docker exec -u www-data "$NC" tar -cf /tmp/mcp-it-recovery-app.tar -C /var/www/html/custom_apps mcp
    docker exec -u www-data "$NC" sh -c 'sha256sum /var/www/html/custom_apps/mcp/js/admin.js > /tmp/mcp-it-recovery-assets.sha256'
    docker exec -u www-data "$NC" php /mcp-it-src/mcp/tests/Integration/ci/recovery.php cut
    # All files in this owned throwaway container, including the credential-bearing backup, die with the run.
    docker exec -u www-data "$NC" mv /var/www/html/custom_apps/mcp /tmp/mcp-it-recovery-displaced
    docker exec -u www-data "$NC" tar -xf /tmp/mcp-it-recovery-app.tar -C /var/www/html/custom_apps
    docker exec -u www-data "$NC" php /mcp-it-src/mcp/tests/Integration/ci/sqlite-copy.php restore
    docker restart "$NC" >/dev/null
    wait_for 'Nextcloud after coordinated recovery' 180 nextcloud_ready
    docker exec -u www-data "$NC" sha256sum -c /tmp/mcp-it-recovery-assets.sha256
    docker exec -u www-data "$NC" php /mcp-it-src/mcp/tests/Integration/ci/recovery.php close
    occ maintenance:mode --off
    docker exec -u www-data "$NC" php /mcp-it-src/mcp/tests/Integration/ci/recovery.php verify
}

# Best effort: gathers logs for the uploaded artifact even when an earlier step failed.
cmd_collect() {
    docker logs "$NC" > "$ARTIFACTS/nextcloud-container.log" 2>&1 || true
    docker logs "$DB" > "$ARTIFACTS/database-container.log" 2>&1 || true
    docker cp "$NC:/var/www/html/data/nextcloud.log" "$ARTIFACTS/nextcloud.log" 2>/dev/null || true
    docker cp "$NC:/tmp/mcp-it-junit.xml" "$ARTIFACTS/junit.xml" 2>/dev/null || true
    docker cp "$NC:/tmp/mcp-it-observations.txt" "$ARTIFACTS/observations.txt" 2>/dev/null || true
    # Full SQL values stay private locally; query-order assertions and observations are in JUnit.
    for log in "$ARTIFACTS"/*; do
        [ -f "$log" ] || continue
        php "$REPO_ROOT/mcp/tests/Integration/ci/redact.php" "$WORK/credentials" "$log" > "$log.redacted"
        mv "$log.redacted" "$log"
    done
}

case "${1:-}" in
    packages) cmd_packages ;;
    tools) cmd_tools ;;
    start-db) cmd_start_db ;;
    start-nextcloud) cmd_start_nextcloud ;;
    install-app) cmd_install_app ;;
    fixtures) cmd_fixtures ;;
    provision) cmd_provision ;;
    report) cmd_report ;;
    test) cmd_test ;;
    recovery) cmd_recovery ;;
    collect) cmd_collect ;;
    *)
        echo "Usage: $0 <packages|tools|start-db|start-nextcloud|install-app|fixtures|provision|report|test|recovery|collect>" >&2
        exit 1
        ;;
esac
