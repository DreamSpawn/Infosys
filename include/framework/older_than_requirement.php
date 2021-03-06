<?php
/**
 * Copyright (C) 2009-2012 Peter Lind
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/gpl.html>.
 *
 * PHP version 5.3+
 *
 * @category  Infosys
 * @package   Framework
 * @author    Peter Lind <peter.e.lind@gmail.com>
 * @copyright 2009-2012 Peter Lind
 * @license   http://www.gnu.org/licenses/gpl.html GPL 3
 * @link      http://www.github.com/Fake51/Infosys
 */

/**
 * deals with config values
 *
 * @category  Infosys
 * @package   Framework
 * @author    Peter Lind <peter.e.lind@gmail.com>
 * @copyright 2009-2012 Peter Lind
 * @license   http://www.gnu.org/licenses/gpl.html GPL 3
 * @link      http://www.github.com/Fake51/Infosys
 */
class OlderThanRequirement implements Requirement
{
    /**
     * public constructor
     *
     * @param int $limit Age limit to test against
     *
     * @access public
     */
    public function __construct($limit)
    {
        $this->limit = intval($limit);
    }

    /**
     * returns true if the requirement is fulfilled by the blob
     *
     * @param FulfilmentBlob $blob Bunch of fulfilments to check requirement against
     *
     * @access public
     * @return bool
     */
    public function isFulfilledBy(FulfilmentBlob $blob)
    {
        foreach ($blob->getFulfilments() as $fulfilment) {
            if (is_a($fulfilment, 'AgeFulfilment') && $fulfilment->isOlderThan($this->limit)) {
                return true;
            }
        }

        return false;
    }
}
