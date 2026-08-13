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

ALTER TABLE llx_googleapi_email ADD UNIQUE INDEX idx_message_id(message_id);

ALTER TABLE llx_googleapi_email ADD INDEX idx_fk_user(fk_user);
ALTER TABLE llx_googleapi_email ADD INDEX idx_object_type(object_type);
ALTER TABLE llx_googleapi_email ADD INDEX idx_object_id(object_id);

ALTER TABLE llx_googleapi_email ADD CONSTRAINT fk_googleapi_email_user FOREIGN KEY (fk_user) REFERENCES llx_user (rowid) ON UPDATE CASCADE ON DELETE CASCADE;
