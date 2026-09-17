-- Copyright (C) 2026  Frédéric FRANCE  <frederic.france@netlogic.fr>
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program.  If not, see http://www.gnu.org/licenses/.

-- Cc/Bcc headers were never captured when caching a Gmail message, so
-- unifiedinbox's "Reply All" had nothing to Cc for Gmail accounts. Bcc is
-- kept too even though it's only ever present on the sender's own Sent copy
-- (Gmail strips it from delivered copies, like any other IMAP/SMTP server) —
-- harmless to store, useful if a Sent-item view ever wants to show it.
ALTER TABLE llx_googleapi_email ADD COLUMN email_cc TEXT NULL AFTER email_to;
ALTER TABLE llx_googleapi_email ADD COLUMN email_bcc TEXT NULL AFTER email_cc;
