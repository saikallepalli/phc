#!/usr/bin/env bash
# Fails the build if known-dangerous patterns are present in the source tree.
# Intentionally simple: grep-based, no dependencies, runs in a second.
set -uo pipefail

fail=0
report() { echo "  [BLOCK] $1"; fail=1; }

echo "== Security gate =="

# --- Unauthenticated admin-creation routes -----------------------------------
if grep -qE "uri === 'reset-admin'" api.php; then
  report "api.php still exposes GET /reset-admin (resets admin password, no auth)"
fi
if grep -qE "uri === 'create-user'" api.php; then
  report "api.php still exposes GET ?_p=create-user (creates admin account, no auth)"
fi

# --- Plaintext password comparison ------------------------------------------
if grep -qE "password_hash'\] *!== *\\\$p" api.php; then
  report "login compares passwords as plaintext; use password_verify()"
fi
if ! grep -q 'password_verify' api.php; then
  report "api.php never calls password_verify() — passwords are not hashed"
fi

# --- Endpoints that must be authenticated -----------------------------------
# /api/export dumps the entire database; it must sit behind requireAdmin().
if awk "/route === 'export'/,/^    }/" api.php | grep -qv 'requireAdmin'; then
  if ! awk "/route === 'export'/,/^    }/" api.php | grep -q 'requireAdmin'; then
    report "/api/export has no requireAdmin() — full DB dump is public"
  fi
fi
if ! awk "/'credentials'/,/^    }/" api.php | grep -q 'require\(Admin\|AnyUser\)'; then
  report "/api/settings/credentials has no auth check"
fi

# --- Secrets that must not be in the repo or the image ----------------------
if [ ! -f .dockerignore ]; then
  report ".dockerignore missing — compose file and .git would ship in the image"
else
  for f in docker-compose.yml .env .git; do
    grep -qxF "$f" .dockerignore || report ".dockerignore does not exclude $f"
  done
fi

# Any password-looking literal in tracked YAML is a hard stop.
if grep -rInE '(DB_PASS|DB_PASSWORD|MYSQL_PASSWORD)[":= ]+[^$"{}[:space:]]' \
     --include='*.yml' --include='*.yaml' . 2>/dev/null \
     | grep -v 'k8s/' | grep -qv 'valueFrom'; then
  report "hardcoded DB password found in a YAML file — move it to Jenkins credentials"
fi

# --- Syntax check every PHP file -------------------------------------------
echo "== PHP lint =="
if command -v php >/dev/null 2>&1; then
  PHPLINT() { php -l "$1"; }
elif command -v docker >/dev/null 2>&1; then
  # No local php — lint inside the same PHP version the image uses.
  PHPLINT() { docker run --rm -v "$PWD:/src:ro" -w /src php:8.3-cli php -l "$1"; }
else
  echo "  [SKIP] neither php nor docker available; cannot lint"
  PHPLINT() { return 0; }
fi

while IFS= read -r phpfile; do
  if ! PHPLINT "$phpfile" >/dev/null 2>&1; then
    echo "  [BLOCK] syntax error in $phpfile"
    PHPLINT "$phpfile" 2>&1 | sed 's/^/        /'
    fail=1
  fi
done < <(find . -name '*.php' -not -path './.git/*')

if [ "$fail" -ne 0 ]; then
  echo
  echo "Security gate FAILED. Fix the items above, or comment out the check in"
  echo "scripts/security-gate.sh if you have accepted the risk deliberately."
  exit 1
fi
echo "Security gate passed."
