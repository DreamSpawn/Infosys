<?php
/**
 * Copyright (C) 2015 Peter Lind
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
 * @copyright 2015 Peter Lind
 * @license   http://www.gnu.org/licenses/gpl.html GPL 3
 * @link      http://www.github.com/Fake51/Infosys
 */

/**
 * payment connector interface
 *
 * @category Infosys
 * @package  Framework
 * @author   Peter Lind <peter.e.lind@gmail.com>
 * @license  http://www.gnu.org/licenses/gpl.html GPL 3
 * @link     http://www.github.com/Fake51/Infosys
 */
interface PaymentConnector
{
    /**
     * generates html output that can be embedded in a webpage and will
     * transfer the user to a payment site
     *
     * @param Deltagere $deltagere         Participant object
     * @param int       $price             Price to pay in Ears
     * @param array     $conneection_links Links into the system, for success, callback and cancel
     * @param string    $payment_text      Optional text for button/links to initiate payment
     *
     * @throws Exception
     * @access public
     * @return string
     */
    public function generateOutput(\Deltagere $deltagere, $price, array $connection_links, $payment_text = 'Betail nu');

    /**
     * parses request data from payment callback
     *
     * @param \Request $request Request data
     *
     * @access public
     * @return array|false
     */
    public function parseCallbackRequest(\Request $request);
}
