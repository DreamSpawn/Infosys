<?php
    /**
     * Copyright (C) 2011  Peter Lind
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
     * PHP version 5
     *
     * @package    MVC
     * @subpackage Models
     * @author     Peter Lind <peter.e.lind@gmail.com>
     * @copyright  2011 Peter Lind
     * @license    http://www.gnu.org/licenses/gpl.html GPL 3
     * @link       http://www.github.com/Fake51/Infosys
     */

    /**
     * handles all data fetching for the activity MVC
     *
     * @package    MVC
     * @subpackage Models
     * @author     Peter Lind <peter.e.lind@gmail.com>
     */
class ApiModel extends Model {

    // massively hackish
    public function authenticate(array $json) {
        $session = $this->dic->get('Session');

        if (empty($json['user']) || empty($json['token']) || !$session->api_token) {
            return false;
        }

        $api_users = $this->db->query("SELECT name, pass FROM api_auth WHERE name = ?", $json['user']);
        if (empty($api_users)) {
            return false;
        }

        if (md5($api_users[0]['pass'] . $session->api_token) === $json['token']) {
            $session = $this->dic->get('Session');
            $session->api_user = $json['user'];
            $session->save();
            return true;
        } else {
            return false;
        }
    }

    public function generateApiKey() {
        $session = $this->dic->get('Session');
        if (!($api_user = $session->api_user)) {
            throw new FrameworkException("No api user");
        }
        $key = md5($api_user . uniqid());
        $session->api_key = $key;
        $session->save();
        return array('api_key' => $key);
    }

    public function getActivityDataForDay($day, $all = false, $app_output = false, $timestamp = 1, $version = 1) {
        $ids = array();
        foreach ($this->db->query("SELECT aktivitet_id FROM afviklinger WHERE DATE(start) = ? OR id IN (SELECT afvikling_id FROM afviklinger_multiblok WHERE DATE(start) = ?)", array($day, $day)) as $row) {
            $ids[] = $row['aktivitet_id'];
        }
        if (empty($ids)) {
            return array();
        }
        return $this->getActivityData($ids, $all, $app_output, 0, $version);
    }

    public function getActivityDataForType($type, $all = false, $app_output = false, $timestamp = 1, $version = 1) {
        $ids = array();
        foreach ($this->db->query("SELECT id FROM aktiviteter WHERE type = ?", array($type)) as $row) {
            $ids[] = $row['id'];
        }
        if (empty($ids)) {
            return array();
        }
        return $this->getActivityData($ids, $all, $app_output, 0, $version);
    }

    /**
     * build the activity structure for the
     * JSON api call to get details of activities
     *
     * @param array $ids        IDs of activities to return data for
     * @param bool  $all        True to include activities that cant be signed up for
     * @param bool  $app_output True if creating output for mobile app
     * @param int   $timestamp  Timestamp to fetch changes after
     *
     * @access public
     * @return array
     */
    public function getActivityData(array $ids, $all = false, $app_output = false, $timestamp = 0, $version = 1) {
        $select = $this->createEntity('Aktiviteter')
            ->getSelect();

        if ($ids) {
            $select->setWhere('id', 'in', $ids);
        }

        if ($timestamp) {
            $select->setWhere('updated', '>', date('Y-m-d H:i:s', $timestamp));
        }

        $result = $this->createEntity('Aktiviteter')->findBySelectMany($select);
        $return = array();
        $multiblok = $this->createEntity('AfviklingerMultiblok')->findAll();
        $multi_ids = array();

        foreach ($multiblok as $multi) {
            $multi_ids[] = $multi->afvikling_id;
        }

        if ($result) {
            foreach ($result as $res) {
                if (($version == 1 && $res->type == 'system') || $res->hidden === 'ja') {
                    continue;
                }

                $act = array(
                    'aktivitet_id' => intval($res->id),
                    'afviklinger' => array(),
                    'info' => array(
                        'title_da'       => $res->navn,
                        'text_da'        => strip_tags(mb_detect_encoding($res->foromtale, 'UTF-8', true) ? $res->foromtale : iconv("ISO-8859-1", "UTF-8", $res->foromtale)),
                        'description_da' => '',
                        'title_en'       => $res->title_en,
                        'text_en'        => strip_tags(mb_detect_encoding($res->description_en, 'UTF-8', true) ? $res->description_en : iconv("ISO-8859-1", "UTF-8", $res->description_en)),
                        'description_en' => '',
                        'author'         => explode(',', $res->author),
                        'price'          => intval($res->pris),
                        'min_player'     => intval($res->min_deltagere_per_hold),
                        'max_player'     => intval($res->max_deltagere_per_hold),
                        'type'           => $res->type,
                        'play_hours'     => floatval($res->varighed_per_afvikling),
                        'language'       => $res->sprog,
                        'wp_id'          => $res->wp_link,
                    ),
                );

                if ($app_output) {
                    foreach ($act['info'] as $key => $value) {
                        $act[$key] = $value;
                    }

                    $act['author'] = implode(',', $act['author']);

                    unset($act['info']);
                }

                foreach ($res->getAfviklinger() as $afvikling) {
                    if ($res->kan_tilmeldes == 'nej' && !$all || (strtotime($afvikling->end) - strtotime($afvikling->start) > 86400)) {
                        continue;
                    }

                    $lokale = $this->createEntity('Lokaler')->findById($afvikling->lokale_id);
                    $time = array(
                        'afvikling_id' => intval($afvikling->id),
                        'aktivitet_id' => intval($res->id),
                        'lokale_id'    => $lokale ? $lokale->id : '',
                        'lokale_navn'  => $lokale ? $lokale->beskrivelse : '',
                        'start'        => $this->makeJsonTimestamp($afvikling->start, $app_output),
                        'end'          => $this->makeJsonTimestamp($afvikling->slut, $app_output),
                        'linked'       => 0,
                        'length'       => round((strtotime($afvikling->slut) - strtotime($afvikling->start)) / 3600, 1),
                    );

                    if ($app_output) {
                        $time['stop'] = $time['end'];
                        unset($time['end']);
                    }

                    $act['afviklinger'][] = $time;

                    if (in_array($afvikling->id, $multi_ids)) {
                        foreach ($multiblok as $multi) {
                            if ($multi->afvikling_id == $afvikling->id) {
                                $time = array(
                                    'afvikling_id' => intval($multi->id),
                                    'aktivitet_id' => intval($res->id),
                                    'start'        => $this->makeJsonTimestamp($multi->start, $app_output),
                                    'end'          => $this->makeJsonTimestamp($multi->slut, $app_output),
                                    'linked'       => $afvikling->id,
                                    'length'       => round((strtotime($multi->slut) - strtotime($multi->start)) / 3600, 1),
                                );

                                if ($app_output) {
                                    $time['stop'] = $time['end'];
                                    unset($time['end']);
                                }

                                $act['afviklinger'][] = $time;
                            }
                        }
                    }
                }
                $return[] = $act;
            }
        }
        return $return;
    }

    /**
     * build the activity structure for the
     * JSON api call to get details of activities
     *
     * @param array $ids
     *
     * @access public
     * @return array
     */
    public function getScheduleStructure(array $ids) {
        $select = $this->createEntity('Aktiviteter')
            ->getSelect();

        $result = $this->createEntity('Aktiviteter')->findBySelectMany($select);
        $return = array();
        $multiblok = $this->createEntity('AfviklingerMultiblok')->findAll();
        $multi_ids = array();
        foreach ($multiblok as $multi) {
            $multi_ids[] = $multi->afvikling_id;
        }

        if ($result) {
            foreach ($result as $res) {
                if ($res->kan_tilmeldes == 'nej') {
                    continue;
                }

                foreach ($res->getAfviklinger() as $afvikling) {
                    if (count($ids) && !in_array($afvikling->id, $ids)) {
                        continue;
                    }

                    $lokale = $this->createEntity('Lokaler')->findById($afvikling->lokale_id);
                    $time = array(
                        'afvikling_id' => intval($afvikling->id),
                        'aktivitet_id' => intval($res->id),
                        'lokale_id'    => $lokale ? $lokale->id : '',
                        'lokale_navn'  => $lokale ? $lokale->beskrivelse : '',
                        'start'        => $this->makeJsonTimestamp($afvikling->start),
                        'end'          => $this->makeJsonTimestamp($afvikling->slut),
                        'linked'       => 0,
                    );
                    $return[] = $time;
                    if (in_array($afvikling->id, $multi_ids)) {
                        foreach ($multiblok as $multi) {
                            if ($multi->afvikling_id == $afvikling->id) {
                                $time = array(
                                    'afvikling_id' => intval($multi->id),
                                    'aktivitet_id' => intval($res->id),
                                    'start' => $this->makeJsonTimestamp($multi->start),
                                    'end' => $this->makeJsonTimestamp($multi->slut),
                                    'linked' => $afvikling->id,
                                );
                                $return[] = $time;
                            }
                        }
                    }
                }
            }
        }

        usort($return, create_function('$a, $b', 'return $a["afvikling_id"] - $b["afvikling_id"];'));

        return $return;
    }

    public function getGDSShiftStructure(array $ids) {
        $select = $this->createEntity('GDSVagter')
            ->getSelect()
            ->setWhere('id', 'in', $ids);
        $result = $this->createEntity('GDSVagter')->findBySelectMany($select);
        $return = array();
        if ($result) {
            foreach ($result as $vagt) {
                $act = array(
                    'gds_id' => intval($vagt->gds_id),
                    'vagt_id' => intval($vagt->id),
                    'start' => $this->makeJsonTimestamp($vagt->start),
                    'end' => $this->makeJsonTimestamp($vagt->slut),
                    'people_needed' => intval($vagt->antal_personer),
                );
            }
            $return[] = $act;
        }
        return $return;
    }

    /**
     * build the GDS structure for the
     * JSON api call to get details of GDS
     *
     * @param array $ids
     *
     * @access public
     * @return array
     */
    public function getGDSStructure(array $ids) {
        $select = $this->createEntity('GDS')
            ->getSelect();
        if ($ids) {
            $select->setWhere('id', 'in', $ids);
        }

        $result = $this->createEntity('GDS')->findBySelectMany($select);
        $return = array();

        if ($result) {
            foreach ($result as $res) {
                $act = array(
                    'gds_id'      => intval($res->id),
                    'vagter'      => array(),
                    'category_id' => $res->category_id,
                    'info'        => array(
                        'title_da'       => $res->navn,
                        'description_da' => $res->beskrivelse,
                        'title_en'       => $res->title_en,
                        'description_en' => $res->description_en,
                        'category_da'    => $res->getCategory()->name_da,
                        'category_en'    => $res->getCategory()->name_en,
                    ),
                );
                $act['vagter'] = $this->prepareShiftStructure($res);
                $return[]      = $act;
            }
        }

        return $return;
    }

    /**
     * build the GDS structure for the
     * JSON api call to get details of GDS
     *
     * @param array $ids
     *
     * @access public
     * @return array
     */
    public function getGDSCategoryStructure(array $ids) {
        $select = $this->createEntity('GDSCategory')
            ->getSelect();
        if ($ids) {
            $select->setWhere('id', 'in', $ids);
        }

        $result = $this->createEntity('GDSCategory')->findBySelectMany($select);
        $return = array();

        if ($result) {
            foreach ($result as $res) {
                $category = array(
                    'gdscategory_id' => $res->id,
                    'category_da'    => $res->name_da,
                    'category_en'    => $res->name_en,
                    'shifts'         => array(),
                );

                foreach ($res->getDIYObjects() as $diy) {
                    $category['shifts'] = array_merge($category['shifts'], $this->prepareShiftStructure($diy));
                }

                $return[] = $category;
            }
        }

        return $return;
    }

    /**
     * creates 
     *
     * @param
     *
     * @access protected
     * @return void
     */
    protected function prepareShiftStructure(GDS $gds)
    {
        $shifts = array();
        foreach ($gds->getVagter() as $shift) {
            $parsed = strtotime($shift->start);
            $time = date('G', $parsed);
            if ($time > 04 && $time < 12) {
                $period = date('Y-m-d ', $parsed) . '04-12';
            } elseif ($time >= 12 && $time <= 17) {
                $period = date('Y-m-d ', $parsed) . '12-17';
            } else {
		if ($time <= 4) {
                    $parsed = strtotime($shift->start . ' - 4 hours');
                }
                $period = date('Y-m-d ', $parsed) . '17-04';
            }

            if (empty($shifts[$period])) {
                $shifts[$period] = array(
                    'gds_id'        => intval($gds->id),
                    'period'        => $period,
                    'people_needed' => intval($shift->antal_personer),
                );
            } else {
                $shifts[$period]['people_needed'] += intval($shift->antal_personer);
            }
        }

        return $shifts;
    }

    /**
     * build the food structure for the
     * JSON api call to get details of food
     *
     * @param array $ids
     *
     * @access public
     * @return array
     */
    public function getFoodStructure(array $ids) {
        $select = $this->createEntity('Mad')
            ->getSelect();
        if ($ids) {
            $select->setWhere('id', 'in', $ids);
        }
        $result = $this->createEntity('Mad')->findBySelectMany($select);
        $return = array();
        if ($result) {
            foreach ($result as $res) {
                $act = array(
                    'mad_id' => intval($res->id),
                    'tider' => array(),
                    'info' => array(
                        'title_da' => $res->kategori,
                        'title_en' => $res->title_en,
                        'price'    => intval($res->pris),
                    ),
                );
                foreach ($res->getMadtider() as $tid) {
                    $time = array(
                        'mad_id' => intval($res->id),
                        'madtid_id' => intval($tid->id),
                        'start' => $this->makeJsonTimestamp(date('Y-m-d', strtotime($tid->dato))),
                    );
                    $act['tider'][] = $time;
                }
                $return[] = $act;
            }
        }
        return $return;
    }

    /**
     * build the entrance structure for the
     * JSON api call to get details of entrance
     *
     * @param array $ids
     *
     * @access public
     * @return array
     */
    public function getEntranceStructure(array $ids) {
        $select = $this->createEntity('Indgang')
            ->getSelect();
        if ($ids) {
            $select->setWhere('id', 'in', $ids);
        }
        $result = $this->createEntity('Indgang')->findBySelectMany($select);
        $return = array();
        if ($result) {
            $weekdays = array('Søndag', 'Mandag', 'Tirsdag', 'Onsdag', 'Torsdag', 'Fredag', 'Lørdag', 'Søndag');
            foreach ($result as $res) {
                switch ($res->type) {
                    case 'entrance-all':
                        $text_dk = "Indgang - partout";
                        $text_en = "Entrance - all days";
                        break;
                    case 'entrance-day':
                        $text_dk = "Indgang - " . $weekdays[date('N', strtotime($res->start))];
                        $text_en = "Entrance - " . date('l', strtotime($res->start));
                        break;
                    case 'sleepover-all':
                        $text_dk = "Overnatning - partout";
                        $text_en = "Overnight - all days";
                        break;
                    case 'sleepover-day':
                        $text_dk = "Overnatning - " . $weekdays[date('N', strtotime($res->start))];
                        $text_en = "Overnight - " . date('l', strtotime($res->start));
                        break;
                }
                $act = array(
                    'indgang_id' => intval($res->id),
                    'type' => $res->type,
                    'price' => intval($res->pris),
                    'start' => $this->makeJsonTimestamp($res->start),
                    'text_da' => $text_dk,
                    'text_en' => $text_en,
                );
                $return[] = $act;
            }
        }
        return $return;
    }

    /**
     * build the wear structure for the
     * JSON api call to get details of wear
     *
     * @param array $ids
     *
     * @access public
     * @return array
     */
    public function getWearStructure(array $ids) {
        $select = $this->createEntity('Wear')
            ->getSelect();
        if ($ids) {
            $select->setWhere('id', 'in', $ids);
        }
        $result = $this->createEntity('Wear')->findBySelectMany($select);
        $return = array();
        if ($result) {
            foreach ($result as $res) {
                $act = array(
                    'wear_id'    => intval($res->id),
                    'size_range' => $res->size_range,
                    'title_da'   => $res->navn,
                    'title_en'   => $res->title_en,
                    'prices'     => array(),
                );
                foreach ($res->getWearpriser() as $price) {
                    $act['prices'][] = array(
                        'wear_id' => intval($res->id),
                        'wearpris_id' => intval($price->id),
                        'brugerkategori_id' => intval($price->brugerkategori_id),
                        'category' => $price->getCategory()->navn,
                        'price' => intval($price->pris),
                    );
                }
                $return[] = $act;
            }
        }
        return $return;
    }

    public function makeJsonTimestamp($time, $app_output = false) {
        $timestamp = strtotime($time);

        if ($app_output) {
            return $timestamp;
        }

        return array(
            'day'       => date('j', $timestamp),
            'month'     => date('n', $timestamp),
            'year'      => date('Y', $timestamp),
            'h'         => date('G', $timestamp),
            'm'         => intval(date('i', $timestamp)),
            'date'      => date('j-n-Y', $timestamp),
            'datetime'  => date('j-n-Y G:i', $timestamp),
            'timestamp' => $timestamp,
            'mysql'     => date('Y-m-d H:i:s', $timestamp),
        );
    }

    public function createParticipant() {
        $bk  = $this->createEntity('BrugerKategorier');
        $sel = $bk->getSelect()->setWhere('navn', '=', 'Deltager');
        $bk->findBySelect($sel);

        $deltager                    = $this->createEntity('Deltagere');
        $deltager->fornavn           = $deltager->efternavn = $deltager->email = $deltager->adresse1 = $deltager->postnummer = $deltager->by = $deltager->land = '';
        $deltager->medical_note      = $deltager->gcm_id = '';
        $deltager->password          = sprintf('%06d', mt_rand(100, 1000000));
        $deltager->brugerkategori_id = $bk->id;
        $deltager->gender            = 'm';
        $deltager->alder             = 0;
        $deltager->birthdate         = '0000-00-00';
        $deltager->insert();
        return array('id' => $deltager->id, 'password' => $deltager->password);
    }

    public function addWear(array $json, $deltager = null) {
        try {
            if (!$deltager) {
                $deltager = $this->createEntity('Deltagere')->findById($json['id']);
            }

            if (empty($json['wear']) || !is_array($json['wear'])) {
                header("HTTP/1.1 403 Bad data - Wear");
                exit;
            }

            foreach ($json['wear'] as $wear) {
                $wearprice = $this->createEntity('WearPriser');
                $wearprice = $wearprice->findBySelect($wearprice->getSelect()->setWhere('wear_id', '=', $wear['id'])->setWhere('brugerkategori_id', '=', $deltager->brugerkategori_id));
                if (!$wearprice) {
                    $participant_category = $this->createEntity('BrugerKategorier');
                    $participant_category->findBySelect($participant_category->getSelect()->setWhere('navn', '=', 'Deltager'));
                    $wearprice = $this->createEntity('WearPriser');
                    $wearprice = $wearprice->findBySelect($wearprice->getSelect()->setWhere('wear_id', '=', $wear['id'])->setWhere('brugerkategori_id', '=', $participant_category->id));
                    if (!$wearprice) {
                        continue;
                    }
                }

                $this->db->exec("INSERT INTO deltagere_wear (deltager_id, wearpris_id, size, antal) VALUES (?, ?, ?, ?)", $deltager->id, $wearprice->id, $wear['size'], $wear['amount']);
            }
        } catch (Exception $e) {
            return 'Failed to add wear choices for participant';
        }
    }

    public function addGDS(array $json) {
        try {
            if (!$deltager) {
                $deltager = $this->createEntity('Deltagere')->findById($json['id']);
            }

            if (empty($json['gds']) || !is_array($json['gds'])) {
                throw new FrameworkException('No data available');
            }

            foreach ($json['gds'] as $gds) {
                $this->db->exec("INSERT INTO deltagere_gdstilmeldinger (deltager_id, category_id, period) VALUES (?, ?, ?)", $deltager->id, $gds['kategori_id'], $gds['period']);
            }
        } catch (Exception $e) {
            return 'Failed to add gds choices for participant';
        }
    }

    public function addFood(array $json) {
        try {
            if (!$deltager) {
                $deltager = $this->createEntity('Deltagere')->findById($json['id']);
            }

            if (empty($json['food']) || !is_array($json['food'])) {
                throw new FrameworkException('No data available');
            }

            foreach ($json['food'] as $food) {
                $this->db->exec("INSERT INTO deltagere_madtider (deltager_id, madtid_id) VALUES (?, ?)", $deltager->id, $food['madtid_id']);
            }
        } catch (Exception $e) {
            return 'Failed to add food choices for participant';
        }
    }

    public function addActivity(array $json, $deltager = null) {
        try {
            if (!$deltager) {
                $deltager = $this->createEntity('Deltagere')->findById($json['id']);
            }

            if (empty($json['activity']) || !is_array($json['activity'])) {
                throw new FrameworkException('No data available ');
            }

            foreach ($json['activity'] as $activity) {
                $this->db->exec("INSERT INTO deltagere_tilmeldinger (deltager_id, prioritet, afvikling_id, tilmeldingstype) VALUES (?, ?, ?, ?)", $deltager->id, $activity['priority'], $activity['schedule_id'], $activity['type']);
            }
        } catch (Exception $e) {
            $e->logException();
            return 'Failed to add activity choices for participant';
        }
    }

    public function addEntrance(array $json, $deltager = null) {
        try {
            if (!$deltager) {
                $deltager = $this->createEntity('Deltagere')->findById($json['id']);
            }

            if (empty($json['entrance']) || !is_array($json['entrance'])) {
                throw new FrameworkException('No data available');
            }

            foreach ($json['entrance'] as $entrance) {
                $this->db->exec("INSERT INTO deltagere_indgang (deltager_id, indgang_id) VALUES (?, ?)", $deltager->id, $entrance['entrance_id']);
            }
        } catch (Exception $e) {
            $e->logException();
            return 'Failed to add entrance choices for participant';
        }
    }

    /**
     * runs through data from a signup to finalize
     * a participants signup
     *
     * @param array $data Data from post
     *
     * @access public
     * @return array
     */
    public function parseSignup(array $data)
    {
        $participant = $this->createEntity('Deltagere')->findById($data['id']);
        if (!$participant->id) {
            $this->fileLog('No participant available for signup');
            return array(
                'status'     => 'fail',
                'failReason' => 'No such participant'
            );
        }

        if (empty($data['participant'])) {
            $this->fileLog('No participant data available for signup');
            return array(
                'status'     => 'fail',
                'failReason' => 'No participant data sent to API'
            );
        }

        $this->setParticipantData($participant, $data['participant']);
        try {
            if (!$participant->update()) {
                throw new FrameworkException('Could not save object');
            }
        } catch (Exception $e) {
            $e->logException();
            return array(
                'status'     => 'fail',
                'failReason' => 'Could not update database with participant data'
            );
        }

        $errors = array();

        if (!empty($data['wear']) && is_array($data['wear'])) {
            $errors[] = $this->addWear($data, $participant);
        }

        if (!empty($data['activity']) && is_array($data['activity'])) {
            $errors[] = $this->addActivity($data, $participant);
        }

        if (!empty($data['entrance']) && is_array($data['entrance'])) {
            $errors[] = $this->addEntrance($data, $participant);
        }

        if (!empty($data['gds']) && is_array($data['gds'])) {
            $errors[] = $this->addGDS($data, $participant);
        }

        if (!empty($data['food']) && is_array($data['food'])) {
            $errors[] = $this->addFood($data, $participant);
        }

        $errors = array_filter($errors);
        if ($errors) {
            $this->fileLog('Failed to create participant relations. Errors: ' . print_r($errors, true) . '. Data: ' . $data);
            $this->cleanParticipantSignup($participant);
            return array(
                'status'     => 'fail',
                'failReason' => implode("\n", $errors),
            );
        } else {
            return array(
                'status'     => 'ok',
                'failReason' => null,
            );
        }
    }

    /**
     * removes all signup choices for a participant
     *
     * @param Deltagere $participant Participant to clean data for
     *
     * @access protected
     * @return void
     */
    protected function cleanParticipantSignup(Deltagere $participant)
    {
        $this->db->exec('DELETE FROM deltagere_tilmeldinger WHERE deltager_id = ?', $participant->id);
        $this->db->exec('DELETE FROM deltagere_madtider WHERE deltager_id = ?', $participant->id);
        $this->db->exec('DELETE FROM deltagere_gdstilmeldinger WHERE deltager_id = ?', $participant->id);
        $this->db->exec('DELETE FROM deltagere_indgang WHERE deltager_id = ?', $participant->id);
        $this->db->exec('DELETE FROM deltagere_wear WHERE deltager_id = ?', $participant->id);
    }

    /**
     * sets data in the participant entity from the api json
     *
     * @param Deltagere $participant Participant object
     * @param array     $data        JSON data
     *
     * @access protected
     * @return void
     */
    protected function setParticipantData(Deltagere $participant, array $data)
    {
        $fields = array(
            'fornavn',
            'efternavn',
            'gender',
            'birthdate',
            'alder',
            'email',
            'tlf',
            'mobiltlf',
            'adresse1',
            'adresse2',
            'postnummer',
            'by',
            'land',
            'medbringer_mobil',
            'sprog',
            'forfatter',
            'international',
            'arrangoer_naeste_aar',
            'deltager_note',
            'flere_gdsvagter',
            'supergm',
            'supergds',
            'rig_onkel',
            'arbejdsomraade',
            'hemmelig_onkel',
            'ready_mandag',
            'ready_tirsdag',
            'oprydning_tirsdag',
            'tilmeld_scenarieskrivning',
            'may_contact',
            'desired_activities',
            'game_reallocation_participant',
            'dancing_with_the_clans',
            'sovesal',
            'ungdomsskole',
            'original_price',
            'scenarie',
            'medical_note',
            'interpreter',
            'skills',
        );

        foreach ($fields as $field) {
            if (isset($data[$field])) {
                $participant->$field = $data[$field];
            }
        }

        $participant->birthdate = strtotime($participant->birthdate) ? $participant->birthdate : '0000-00-00';

        $bk  = $this->createEntity('BrugerKategorier');
        $sel = $bk->getSelect()->setWhere('navn', '=', $data['brugertype']);
        $bk->findBySelect($sel);
        if ($bk->id) {
            $participant->brugerkategori_id = $bk->id;
        }
    }

    /**
     * returns data from given graph, if possible
     *
     * @param string $name Name of graph to fetch data for
     *
     * @throws Exception
     * @access public
     * @return array
     */
    public function fetchGraphData($name)
    {
        $model = $this->factory('Graph');
        switch ($name) {
        case 'signupsOverTime':
            return $model->getSignupData();

        case 'signupsOverTimeAgeGrouped':
            return $model->getAgeGroupedSignupData();

        case 'accommodationAgeGrouped':
            return $model->getAgeGroupedAccommodationData();

        default:
            throw new FrameworkException('No such graph');
        }

        return $data;
    }

    /**
     * method docblock
     *
     * @param
     *
     * @access public
     * @return array
     */
    public function getActivityStructure()
    {
        $friendly_names = array(
            'navn'                   => 'Titel (Dk)',
            'kan_tilmeldes'          => 'Muligt at tilmelde sig',
            'note'                   => 'Noter',
            'foromtale'              => 'Foromtale (Dk)',
            'varighed_per_afvikling' => 'Varighed (timer)',
            'min_deltagere_per_hold' => 'Minimum deltagere',
            'max_deltagere_per_hold' => 'Maximum deltagere',
            'spilledere_per_hold'    => 'Spilledere per hold',
            'pris'                   => 'Pris',
            'lokale_eksklusiv'       => 'Skal foregå i eget lokale',
            'wp_link'                => 'Wordpress ID',
            'teaser_dk'              => 'Teaser (Dk)',
            'teaser_en'              => 'Teaser (Gb)',
            'title_en'               => 'Teaser (Gb)',
            'description_en'         => 'Foromtale (Gb)',
            'author'                 => 'Forfatter',
            'type'                   => 'Aktivitetstype',
            'tids_eksklusiv'         => 'Udelukker samtidige aktiviteter',
            'sprog'                  => 'Sprog',
            'replayable'             => 'Kan spilles flere gange',
        );

        $struct = $this->convertColumnInfoToHtmlStructure($this->createEntity('Aktiviteter')->getColumnInfo());
        unset($struct['id']);

        foreach ($struct as $field => $type) {
            $struct[$field] = array(
                'type'          => $type,
                'friendly_name' => isset($friendly_names[$field]) ? $friendly_names[$field] : '',
            );
        }

        return $struct;
    }

    /**
     * converts a column info structure to html meaningful terms
     *
     * @param array $structure Column info structure to convert
     *
     * @access protected
     * @return array
     */
    protected function convertColumnInfoToHtmlStructure(array $structure)
    {
        $output = array();
        foreach ($structure as $field => $type) {
            if ($type == 'text') {
                $output[$field] = 'textarea';

            } elseif (strpos($type, 'varchar') !== false) {
                $output[$field] = 'text';

            } elseif (preg_match('/enum\((.*)\)/', $type, $match)) {
                $fields         = array_map(function($x) {return trim($x, "'\"");}, explode(',', $match[1]));
                $output[$field] = 'select:' . implode('|', array_diff($fields, array('system')));

            } elseif (strpos($type, 'int') !== false) {
                $output[$field] = 'number:int';

            } elseif (strpos($type, 'float') !== false) {
                $output[$field] = 'number:float';

            } else {
                $output[$field] = 'text';
            }
        }

        return $output;
    }

    /**
     * returns array of values for entity
     *
     * @param DBObject $entity Entity to format
     *
     * @access public
     * @return array
     */
    public function formatEntityForJson(DBObject $entity)
    {
        $output = array();
        foreach ($entity->getColumns() as $column) {
            $output[$column] = $entity->$column;
        }

        return $output;
    }

    /**
     * attempts to find an activity by a field and a value
     *
     * @param string $field Field to search by
     * @param mixed  $value Value to search with
     *
     * @throws Exception
     * @access public
     * @return null|DBObject
     */
    public function findActivityByField($field, $value)
    {
        $activity = $this->createEntity('Aktiviteter');
        if (!in_array($field, $activity->getColumns())) {
            throw new FrameworkException('No such field in entity');
        }

        $select = $activity->getSelect()
            ->setWhere($field, '=', $value);

        $result = $this->createEntity('Aktiviteter')->findBySelect($select);
        return $result ? $result : null;
    }

    /**
     * creates an activity based on POST data
     *
     * @param array $data Data to use for activity creation
     *
     * @throws Exception
     * @access public
     * @return void
     */
    public function createActivity(array $data)
    {
        $activity = $this->createEntity('Aktiviteter');
        $this->fillActivity($activity, $data);

        $activity->insert();
    }

    /**
     * method docblock
     *
     * @param
     *
     * @access protected
     * @return void
     */
    protected function fillActivity(DBObject $activity, array $data)
    {
        foreach ($activity->getColumns() as $column) {
            if ($column == 'id' || $column == 'title_en') {
                continue;
            }

            $activity->$column = isset($data[$column]) ? $data[$column] : '';
        }

        $activity->updated = date('Y-m-d H:i:s');

        $activity->convertProblematicToDefault();
    }

    /**
     * updates an activity based on POST data
     *
     * @param array $data Data to use for activity creation
     * @param int   $id   ID of activity to update
     *
     * @throws Exception
     * @access public
     * @return void
     */
    public function updateActivity(array $data, $id)
    {
        $activity = $this->createEntity('Aktiviteter')->findById($id);
        if (!$activity->isLoaded()) {
            throw new FrameworkException('Could not find activity by id');
        }

        $this->fillActivity($activity, $data);

        $activity->update();
    }

    /**
     * returns a participant if it exists
     *
     * @param int $id Id of participant to find
     *
     * @access public
     * @return null|Deltagere
     */
    public function findParticipant($id)
    {
        return $this->createEntity('Deltagere')->findById($id);
    }

    /**
     * returns JSON object of participants schedule for fastaval
     *
     * @param DBObject $participant Participant to get schedule for
     *
     * @access public
     * @return array
     */
    public function getParticipantSchedule(DBObject $participant, $version = 1)
    {
        $sleep = 0;
        foreach ($participant->getIndgang() as $entrance) {
            if ($entrance->isSleepTicket()) {
                $sleep = 1;
                break;
            }
        }

        $sleep = $participant->sovesal == 'ja' ? 2 : $sleep;

        $return = array(
            'id'         => $participant->id,
            'name'       => trim($participant->fornavn . ' ' . $participant->efternavn),
            'checked_in' => strtotime($participant->checkin_time) > 1 ? 1 : 0,
            'messages'   => $participant->beskeder,
            'sleep'      => $sleep,
            'food' => array(
            ),
            'wear' => array(
            ),
            'scheduling' => array(),
        );

        foreach ($participant->getWear() as $wearorder) {
            $wear = $wearorder->getWear();

            $return['wear'][] = array(
                'amount'   => $wearorder->antal,
                'size'     => $wearorder->size,
                'title_da' => $wear->navn,
                'title_en' => $wear->title_en,
                'wear_id'  => $wear->id,
            );
        }

        $foodtime_links = $participant->getFoodOrderLinks();
        $ids            = array_map(function ($x) {
            return $x->madtid_id;
        }, $foodtime_links);

        $combined = array_combine($ids, $foodtime_links);

        foreach ($participant->getMadTider() as $foodtime) {
            $food = $foodtime->getMad();

            $start = strtotime($foodtime->dato);
            $end   = strtotime($foodtime->dato) + 7200;
            if (isset($combined[$foodtime->id]) && $combined[$foodtime->id]->time_type) {
                $start = $start + ($combined[$foodtime->id]->time_type - 1) * 1800;
                $end   = $start + 1800;
            }

            $return['food'][] = array(
                'time'     => $start,
                'time_end' => $end,
                'title_da' => $food->kategori,
                'title_en' => $food->title_en,
                'food_id'  => $food->id,
                'time_id'  => $foodtime->id,
            );
        }

        foreach ($participant->getPladser() as $play) {
            $schedule  = $play->getAfvikling();
            $activity  = $schedule->getAktivitet();
            //$room      = $play->getLokale();
            $room_name = $schedule->getRoom();

            if ($activity->hidden === 'ja' || ($version == 1 && $activity->type == 'system')) {
                continue;
            }

            $return['scheduling'][] = array(
                'type'          => 'activity',
                'activity_type' => $activity->type,
                'id'   => $activity->id,
                'title_da'  => $activity->navn,
                'title_en' => $activity->title_en,
                'start' => strtotime($schedule->start),
                'stop' => strtotime($schedule->slut),
                'room_da'  => $room_name,
                'room_en'  => $room_name,
            );
        }

        foreach ($participant->getGDSVagter() as $shift) {
            $diy = $shift->getGDS();

            $return['scheduling'][] = array(
                'type' => 'gds',
                'activity_type' => 'gds',
                'id'    => $diy->id,
                'title_da' => $diy->navn,
                'title_en' => $diy->title_en,
                'room_da' => $diy->moedested,
                'room_en' => $diy->moedested_en,
                'start' => strtotime($shift->start),
                'stop' => strtotime($shift->slut),
            );
            
        }

        usort($return['scheduling'], function($a, $b) {
            return $a['start']['timestamp'] - $b['start']['timestamp'];
        });

        return $return;
    }

    /**
     * registers a GCM id for a participant
     *
     * @param DBObject $participant Participant to register id for
     * @param array    $json        Data from request
     *
     * @throws FrameworkException
     * @access public
     * @return void
     */
    public function registerApp(DBObject $participant, $json)
    {
        if (empty($json['gcm_id'])) {
            throw new FrameworkException('Lacking gcm_id in post');
        }

        $participant->gcm_id = $json['gcm_id'];
        $participant->update();
    }

    /**
     * unregisters a GCM id for a participant
     *
     * @param DBObject $participant Participant to register id for
     * @param array    $json        Data from request
     *
     * @throws FrameworkException
     * @access public
     * @return void
     */
    public function unregisterApp(DBObject $participant, $json)
    {
        $participant->gcm_id = '';
        $participant->update();
    }

    public function getScheduleInfo(DBObject $activity)
    {
        $output = array();

        foreach ($activity->getAfviklinger() as $schedule) {
            $output[] = array(
                'start'   => strtotime($schedule->start),
                'end'     => strtotime($schedule->slut),
                'room_en' => $schedule->getRoom(),
                'room_da' => $schedule->getLokale(),
            );

            foreach ($schedule->getMultiBlok() as $subschedule) {
                $output[] = array(
                    'start'   => strtotime($subschedule->start),
                    'end'     => strtotime($subschedule->slut),
                    'room_en' => $subschedule->getRoom(),
                    'room_da' => $subschedule->getLokale(),
                );
            }
        }

        return $output;
    }
}
