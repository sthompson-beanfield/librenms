<?php

/**
 * ApcOnBatteryTest.php
 *
 * -Description-
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @link       https://www.librenms.org
 *
 * @author     Andy Norwood(bonzo81)
 */

namespace LibreNMS\Tests\Feature\SnmpTraps;

use LibreNMS\Enum\Severity;

class ApcOnBatteryTest extends SnmpTrapTestCase
{
    /**
     * Test ApcOnBattery handle
     *
     * @return void
     */
    public function testApcOnBattery(): void
    {
        $this->assertTrapLogsMessage(<<<'TRAP'
{{ hostname }}
UDP: [{{ ip }}]:57602->[10.0.0.1]:162
SNMPv2-MIB::sysUpTime.0 18:30:30.32
SNMPv2-MIB::snmpTrapOID.0 PowerNet-MIB::upsOnBattery
PowerNet-MIB::mtrapargsString "The UPS has switched to battery backup power."
SNMPv2-MIB::snmpTrapEnterprise.0 PowerNet-MIB::apc
TRAP,
            'The UPS has switched to battery backup power.',
            'Could not handle testApcOnBattery trap',
            [Severity::Warning],
        );
    }
}
