#!/usr/bin/env python3
"""Test the exact generated SELECTs against portable SQLite fixtures.

This checks filters/joins with a real SQL engine. It does NOT certify MySQL,
PDO, transaction isolation, or a production PrestaShop installation.
Python is a development-test dependency only, never a deployment dependency.
"""
import json
import pathlib
import sqlite3
import subprocess
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]


class SourceQueriesTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        output = subprocess.check_output(
            ["php", "-r", "require $argv[1]; echo json_encode(\\PrestaShopMautic\\sourceQueries('ps_'));", str(ROOT / "import.php")],
            text=True,
        )
        cls.queries = json.loads(output)

    def setUp(self):
        self.db = sqlite3.connect(":memory:")
        self.db.row_factory = sqlite3.Row
        self.db.executescript("""
            CREATE TABLE ps_customer (
                id_customer INTEGER PRIMARY KEY, id_shop INTEGER,
                email TEXT, firstname TEXT, lastname TEXT, is_guest INTEGER,
                id_default_group INTEGER, newsletter INTEGER, active INTEGER, deleted INTEGER
            );
            CREATE TABLE ps_customer_group (id_customer INTEGER, id_group INTEGER);
            CREATE TABLE ps_group_lang (id_group INTEGER, id_lang INTEGER, name TEXT);
            CREATE TABLE ps_emailsubscription (id INTEGER PRIMARY KEY, id_shop INTEGER, email TEXT, active INTEGER);
            INSERT INTO ps_group_lang VALUES (2,1,'Guest'),(3,1,'Customer'),(7,1,'Wholesale'),(3,2,'Zakaznik');
            INSERT INTO ps_customer VALUES
                (1,1,'yes@example.test','Jane','Novak',0,3,1,1,0),
                (2,1,'no@example.test','John','Novak',0,3,0,1,0),
                (3,1,'disabled@example.test','Off','Account',0,3,1,0,0),
                (4,1,'deleted@example.test','Gone','Account',0,3,1,1,1),
                (5,2,'other-shop@example.test','Other','Shop',0,3,1,1,0),
                (6,1,'guest@example.test','Guest','Buyer',1,2,1,1,0),
                (7,1,'no-group@example.test','No','Group',0,9,1,1,0);
            INSERT INTO ps_customer_group VALUES (1,3),(1,7),(2,3),(5,3),(6,2);
            INSERT INTO ps_emailsubscription VALUES
                (1,1,'homepage@example.test',1),
                (2,1,'pending@example.test',0),
                (3,2,'other-newsletter@example.test',1),
                (4,1,'yes@example.test',1);
        """)

    def tearDown(self):
        self.db.close()

    def customers(self, shop=1, language=1):
        return list(self.db.execute(self.queries["customers"],
                                   {"shop": shop, "group_language": language, "default_language": language}))

    def test_only_enabled_undeleted_newsletter_customers(self):
        self.assertEqual({1, 6, 7}, {r["id_customer"] for r in self.customers()})

    def test_customer_shop_filter(self):
        self.assertEqual([5], [r["id_customer"] for r in self.customers(shop=2)])

    def test_multiple_memberships_not_lost(self):
        rows = [r for r in self.customers() if r["id_customer"] == 1]
        self.assertEqual([3, 7], [r["id_group"] for r in rows])
        self.assertEqual(["Customer", "Customer"], [r["default_group_name"] for r in rows])

    def test_only_requested_group_language(self):
        rows = [r for r in self.customers(language=2) if r["id_customer"] == 1]
        self.assertEqual(["Zakaznik", None], [r["group_name"] for r in rows])
        self.assertEqual(["Zakaznik", "Zakaznik"], [r["default_group_name"] for r in rows])

    def test_missing_membership_or_translation_does_not_drop_customer(self):
        row = next(r for r in self.customers() if r["id_customer"] == 7)
        self.assertIsNone(row["id_group"])
        self.assertIsNone(row["default_group_name"])

    def test_guest_account_retains_its_real_type_and_group(self):
        row = next(r for r in self.customers() if r["id_customer"] == 6)
        self.assertEqual(1, row["is_guest"])
        self.assertEqual("Guest", row["group_name"])

    def test_unconfirmed_newsletter_rows_excluded(self):
        rows = self.db.execute(self.queries["newsletter"], {"shop": 1}).fetchall()
        self.assertEqual([1, 4], [r["id"] for r in rows])

    def test_newsletter_shop_filter(self):
        rows = self.db.execute(self.queries["newsletter"], {"shop": 2}).fetchall()
        self.assertEqual([3], [r["id"] for r in rows])


if __name__ == "__main__":
    unittest.main(verbosity=2)
