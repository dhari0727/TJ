"""
JourneyAI — shared MySQL connection helper (pymysql).

Mirrors the PHP app's hardcoded local XAMPP credentials (connection.php):
host=localhost, user=root, password='', db=project.

Usage:
    from ml.db import get_connection, fetch_df
    conn = get_connection()
    ...
    df = fetch_df("SELECT * FROM journals")
"""
import os
import pymysql
import pymysql.cursors

# Local XAMPP/MariaDB defaults — overridable via env vars for flexibility.
DB_CONFIG = {
    "host": os.environ.get("JOURNEYAI_DB_HOST", "127.0.0.1"),
    "port": int(os.environ.get("JOURNEYAI_DB_PORT", "3306")),
    "user": os.environ.get("JOURNEYAI_DB_USER", "root"),
    "password": os.environ.get("JOURNEYAI_DB_PASS", ""),
    "database": os.environ.get("JOURNEYAI_DB_NAME", "project"),
    "charset": "utf8mb4",
}


def get_connection(dict_cursor: bool = True):
    """Open a new pymysql connection. Caller is responsible for closing."""
    cursorclass = (
        pymysql.cursors.DictCursor if dict_cursor else pymysql.cursors.Cursor
    )
    return pymysql.connect(cursorclass=cursorclass, autocommit=False, **DB_CONFIG)


def fetch_all(sql, params=None):
    """Run a SELECT and return a list of dict rows."""
    conn = get_connection()
    try:
        with conn.cursor() as cur:
            cur.execute(sql, params or ())
            return cur.fetchall()
    finally:
        conn.close()


def fetch_df(sql, params=None):
    """Run a SELECT and return a pandas DataFrame.

    Builds the frame explicitly from a plain cursor (column names from
    cursor.description) to avoid pandas' SQLAlchemy-only read_sql path.
    """
    import pandas as pd

    conn = get_connection(dict_cursor=False)
    try:
        with conn.cursor() as cur:
            cur.execute(sql, params or ())
            rows = cur.fetchall()
            cols = [d[0] for d in cur.description]
        return pd.DataFrame(rows, columns=cols)
    finally:
        conn.close()


def executemany(sql, rows):
    """Bulk-insert helper. `rows` is a list of tuples/lists. Commits on success."""
    conn = get_connection(dict_cursor=False)
    try:
        with conn.cursor() as cur:
            cur.executemany(sql, rows)
        conn.commit()
        return cur.rowcount
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()


def ping():
    """Quick connectivity + schema check. Returns (ok: bool, message: str)."""
    try:
        rows = fetch_all("SHOW TABLES")
        names = {list(r.values())[0] for r in rows}
        expected = {"db", "db1", "db2", "db3", "signup",
                    "destinations", "interactions", "journal_features", "journals"}
        missing = expected - names
        if missing:
            return False, f"Connected, but missing objects: {sorted(missing)}"
        return True, f"OK — {len(names)} tables/views present."
    except Exception as e:  # pragma: no cover
        return False, f"Connection failed: {e}"


if __name__ == "__main__":
    ok, msg = ping()
    print(("[OK] " if ok else "[FAIL] ") + msg)
