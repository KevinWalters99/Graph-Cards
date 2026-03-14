"""
Card Graph — Shared Configuration

Reads credentials from .env file or environment variables.
All Python scripts import from here instead of hardcoding credentials.

Priority:
  1. Environment variables (for Docker containers)
  2. .env file (searched upward from this script's directory)
"""
import os

_loaded = {}


def _find_env_file():
    """Search upward from this script's directory for .env file."""
    # Known locations to check (NAS and dev)
    candidates = [
        os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '.env'),   # cardgraph/.env
        os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', '.env'),  # project root/.env
        '/volume1/web/cardgraph/.env',   # NAS absolute path
        '/volume1/web/.env',             # NAS alt path
    ]
    for path in candidates:
        resolved = os.path.realpath(path)
        if os.path.isfile(resolved):
            return resolved
    return None


def _load_env():
    """Parse .env file into dict. Only runs once."""
    global _loaded
    if _loaded:
        return _loaded

    env_file = _find_env_file()
    if env_file:
        with open(env_file, 'r') as f:
            for line in f:
                line = line.strip()
                if not line or line.startswith('#'):
                    continue
                if '=' in line:
                    key, _, value = line.partition('=')
                    _loaded[key.strip()] = value.strip()
    return _loaded


def get(key, default=None):
    """Get a config value: env var first, then .env file, then default."""
    return os.environ.get(key) or _load_env().get(key) or default


# ─── Database Config ──────────────────────────────────────────────

DB_CONFIG = {
    'host':     get('CG_DB_HOST', '127.0.0.1'),
    'port':     int(get('CG_DB_PORT', '3306')),
    'user':     get('CG_DB_USER', 'cg_app'),
    'password': get('CG_DB_PASSWORD', ''),
    'database': get('CG_DB_NAME', 'card_graph'),
    'charset':  'utf8mb4',
}

# ─── NAS Config ───────────────────────────────────────────────────

NAS_IP = get('CG_NAS_IP', '127.0.0.1')
NAS_PORT = get('CG_NAS_PORT', '8880')
NAS_BASE_URL = f"http://{NAS_IP}:{NAS_PORT}"

# ─── Scheduler ────────────────────────────────────────────────────

SCHEDULER_KEY = get('CG_SCHEDULER_KEY', '')

# ─── Yahoo (eBay import) ─────────────────────────────────────────

YAHOO_EMAIL = get('CG_YAHOO_EMAIL', '')
YAHOO_APP_PASSWORD = get('CG_YAHOO_APP_PASSWORD', '')

# ─── Site Admin ───────────────────────────────────────────────────

ADMIN_PASSWORD = get('CG_ADMIN_PASSWORD', '')
