-- Copyright (C) 2025 Jean-Rémi TAPONIER    <jean-remi@netlogic.fr>
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
-- along with this program.  If not, see <http://www.gnu.org/licenses/>.

CREATE TABLE llx_googleapi_email (
    rowid INTEGER UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    date DATETIME NOT NULL,
    email_from VARCHAR(255) NOT NULL,
    email_to TEXT NOT NULL,
    outgoing BOOLEAN NOT NULL,
    subject TEXT,
    snippet TEXT,
    object_type VARCHAR(64),
    object_id INTEGER,
    message_id VARCHAR(128),
    fk_user INTEGER NOT NULL,
    tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
