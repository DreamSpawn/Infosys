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
 * PHP version 5
 *
 * @category  Infosys
 * @package   Models
 * @author    Peter Lind <peter.e.lind@gmail.com>
 * @copyright 2009-2012 Peter Lind
 * @license   http://www.gnu.org/licenses/gpl.html GPL 3
 * @link      http://www.github.com/Fake51/Infosys
 */

/**
 * handles all data fetching for the boardgames controller
 *
 * @category Infosys
 * @package  Models
 * @author   Peter Lind <peter.e.lind@gmail.com>
 * @license  http://www.gnu.org/licenses/gpl.html GPL 3
 * @link     http://www.github.com/Fake51/Infosys
 */
class BoardgamesModel extends Model
{
    public function fetchAllData()
    {
        $data = array(
            'gameData'        => $this->fetchGameData(),
            'participantData' => $this->fetchPartipantData(),
            'activityData'    => $this->fetchActivityData(),
            'notes'           => $this->fetchNote(),
            'stats'           => $this->fetchStats(),
        );

        return $data;
    }

    public function fetchStats()
    {
        $return = array();

        $query = '
SELECT COUNT(*) AS count FROM boardgameevents WHERE TYPE = "borrowed"
';

        foreach ($this->db->query($query) as $row) {
            $return['Udlån samlet'] = $row['count'];
        }

        $query = '
SELECT DATE(timestamp) AS date, COUNT(*) AS count FROM boardgameevents WHERE TYPE = "borrowed" GROUP BY DATE(timestamp) ORDER BY date
';

        foreach ($this->db->query($query) as $row) {
            $return['Udlån ' . $row['date']] = $row['count'];
        }

        $query = '
SELECT b.name, COUNT(*) AS count FROM boardgames AS b JOIN boardgameevents AS be ON be.boardgame_id = b.id GROUP BY b.name ORDER BY count DESC LIMIT 3;
';

        $index = 1;

        foreach ($this->db->query($query) as $row) {
            $return['Top ' . $index++] = $row['name'] . ' (' . $row['count'] . ')';
        }

        return $return;
    }

    /**
     * fetch all game details
     *
     * @access protected
     * @return array
     */
    public function fetchGameData()
    {
        $games = $this->createEntity('Boardgame')->findAll();

        $output = array();

        foreach ($games as $game) {
            $details = array(
                'id'      => intval($game->id),
                'barcode' => $game->barcode,
                'name'    => $game->name,
                'owner'   => $game->owner,
                'comment' => $game->comment,
                'log'     => $game->getLog(),
            );

            if ($game->isBorrowed()) {
                $details['borrowed'] = $game->getBorrowedDetails();

            } elseif ($game->isFinished()) {
                $details['returned'] = $game->getFinishedDetails();

            }

            $output[] = $details;
        }

        return $output;
    }

    public function fetchPartipantData()
    {
        $select = $this->createEntity('Deltagere')->getSelect();

        $select->setWhere('annulled', '=', 'nej')
            ->setWhere('signed_up', '>', '0000-00-00');

        $participants = array();

        foreach ($this->createEntity('Deltagere')->findBySelectMany($select) as $participant) {
            if (trim($participant->getName() == "")) {
                continue;
            }

            $participants[] = array(
                'id'      => intval($participant->id),
                'name'    => $participant->getName(),
                'barcode' => $participant->getEan8Number(),
            );
        }

        return $participants;
    }

    public function fetchActivityData()
    {
        $select = $this->createEntity('Hold')->getSelect();

        $select->setWhere('lokale_id', '=', 64);

        $groups = $this->createEntity('Hold')->findBySelectMany($select);

        usort($groups, function ($a, $b) {
            return strtotime($a->getSchedule()->start) < strtotime($b->getSchedule()->start) ? -1 : 1;
        });

        $return = array();

        foreach ($groups as $group) {
            $data = array(
                'timestamp' => $group->getSchedule()->start,
                'name'      => $group->getActivity()->navn,
                'attendees' => array(),
            );

            foreach ($group->getPladser() as $spot) {
                $data['attendees'][] = $spot->getParticipant()->getName();
            }

            $return[] = $data;
        }

        return $return;
    }

    public function createBoardgame(RequestVars $post)
    {
        if (!$post->name || !$post->owner || !$post->barcode) {
            throw new Exception('Missing data for boardgame creation');
        }

        $boardgame = $this->createEntity('Boardgame');

        $boardgame->name    = $post->name;
        $boardgame->owner   = $post->owner;
        $boardgame->barcode = $post->barcode;
        $boardgame->comment = isset($post->comment) ? $post->comment : '';

        $boardgame->insert();

        $this->log('Brætspil (' . $boardgame->id . ', ' . $post->name . ') oprettet af ' . $this->getLoggedInUser()->user, 'Boardgames', $this->getLoggedInUser());

        return $boardgame;
    }

    public function updateBoardgameStatus(RequestVars $post)
    {
        if (!$post->gameId || !$post->status) {
            throw new Exception('Missing data for boardgame update');
        }

        $select = $this->createEntity('Boardgame')->getSelect();

        $select->setWhere('id', '=', $post->gameId);

        if (!($game = $this->createEntity('Boardgame')->findBySelect($select))) {
            throw new Exception('No game with id: ' . $post->gameId);
        }

        $data = array();

        if ($post->status === 'borrowed') {
            $data['participant_id'] = isset($post->participantId) ? $post->participantId : '';
            $data['participant']    = isset($post->participant) ? $post->participant : '';
            $data['comment']        = isset($post->comment) ? $post->comment : '';
        }

        $game->setStatus($post->status, $data);

        $this->log('Brætspil (' . $game->id . ', ' . $game->name . ') status rettet til ' . $post->status . ' af ' . $this->getLoggedInUser()->user, 'Boardgames', $this->getLoggedInUser());
    }

    public function editBoardgame(RequestVars $post)
    {
        if (!$post->gameId || !$post->name || !$post->owner) {
            throw new Exception('Missing data for boardgame update');
        }

        $select = $this->createEntity('Boardgame')->getSelect();

        $select->setWhere('id', '=', $post->gameId);

        if (!($game = $this->createEntity('Boardgame')->findBySelect($select))) {
            throw new Exception('No game with id: ' . $post->gameId);
        }

        $game->name = $post->name;
        $game->owner = $post->owner;
        $game->barcode = !empty($post->barcode) ? $post->barcode : '';
        $game->comment = !empty($post->comment) ? $post->comment : '';

        $game->update();

        $this->log('Brætspil (' . $game->id . ', ' . $post->name . ') redigeret af ' . $this->getLoggedInUser()->user, 'Boardgames', $this->getLoggedInUser());

        return $game;
    }

    public function parseSpreadsheetData(RequestVars $post)
    {
        if (empty($post->input)) {
            throw new Exception('No input from request');
        }

        $required_headers = array(
                             'Navn',
                             'Ejer',
                             'Stregkode',
                             'Kommentar',
                            );

        $header_index = array();

        $data = explode("\n", str_replace(array("\r\n", "\r"), "\n", $post->input));

        $headers = array_flip(explode("\t", array_shift($data)));

        foreach ($required_headers as $header) {
            if (!isset($headers[$header])) {
                throw new Exception('Lacking header ' . $header);
            }

            $header_index[$header] = $headers[$header];
        }

        $query = 'DELETE FROM boardgameevents;';
        $this->db->exec($query);

        $query = 'DELETE FROM boardgames;';
        $this->db->exec($query);

        foreach ($data as $row) {
            if (strlen(trim($row)) === 0) {
                continue;
            }

            $columns = explode("\t", $row);

            $game = $this->createEntity('Boardgame');

            $game->name = $columns[$header_index['Navn']];
            $game->owner = $columns[$header_index['Ejer']];
            $game->barcode = $columns[$header_index['Stregkode']];
            $game->comment = $columns[$header_index['Kommentar']];

            $game->insert();
        }

        $this->log('Brætspils data blev upload og resat af ' . $this->getLoggedInUser()->user, 'Boardgames', $this->getLoggedInUser());

        return true;
    }

    protected function fetchNote()
    {
        $query = '
SELECT
    note
FROM notes
WHERE area = "boardgames"';

        foreach ($this->db->query($query) as $row) {
            return $row['note'];
        }

        return '';
    }

    public function updateBoardgamesNote(RequestVars $post)
    {
        $query = '
INSERT INTO notes
SET note = ?, updated = NOW(), area = "boardgames"
ON DUPLICATE KEY UPDATE note = ?';

        $this->db->exec($query, array($post->note, $post->note));

        return $this;
    }
}
