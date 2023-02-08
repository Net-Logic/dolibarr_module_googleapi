-- Copyright (C) 2019-2021  Frédéric France         <frederic.france@netlogic.fr>
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
-- uuid, id, resource, applicationId, changeType, clientState, expirationDateTime, creatorId
CREATE TABLE llx_googleapi_mailboxes(
    rowid INTEGER NOT NULL AUTO_INCREMENT PRIMARY KEY,
    userid INT,
    unread int,
    tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
