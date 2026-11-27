# Druckblatt (Lesezeichen im Plenty-Backend)

Gehört nicht zum Plugin-Build. Liegt nur zur Versionierung hier.

- `druckblatt-quelle.js`: lesbare Quelle
- `druckblatt.js`: mit terser verkleinert, liegt im Plenty-Dateimanager unter `content/w39tools/druckblatt.js`

Das Lesezeichen lädt `https://cdn02.plentyone.com/wp3tnj77y4cz/frontend/content/w39tools/druckblatt.js` auf einer geöffneten Auftragsseite im Backend.

27.11.2026: Aufträge mit Druck-Ref (Druck-Designer ab 26.11.2026 abends) lesen die Druckangaben aus `GET /rest/s39staffel/druck?ref=…` (braucht den CORS-Kopf aus Plugin 0.4.3).
