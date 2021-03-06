<?php

/**
 * Created by PhpStorm.
 * User: aklassen
 * Date: 23.07.15
 * Time: 12:14
 */

require_once 'OCModel.php';
require_once 'OCSeriesModel.php';
require_once dirname(__FILE__) .'/../classes/OCRestClient/SearchClient.php';

class OCCourseModel
{

    /**
     * This is the maximum number of seconds that unread entries are
     * marked as new.
     */
    const LAST_VISIT_MAX = 7776000; // 90 days

    /**
     * @param $course_id
     */
    function __construct($course_id)
    {
        if (!$course_id) {
            throw new Exception('Missing course-id!');
        }

        $this->setCourseID($course_id);
        // take care of connected series
        $cseries = OCSeriesModel::getConnectedSeries($this->getCourseID(),true);

        if(!empty($cseries)){
            $current_seriesdata = array_pop($cseries);
            $this->setSeriesMetadata($current_seriesdata);
            $this->setSeriesID($current_seriesdata['identifier']);
        } else {
            $this->setSeriesID(false);
        }

    }

    public function getCourseID(){
        return $this->course_id;
    }

    public function getSeriesID(){
        return $this->series_id;
    }

    public function getSeriesMetadata(){
        return $this->seriesMetadata;
    }

    public function setSeriesID($series_id){
        $this->series_id = $series_id;
    }

    public function setCourseID($course_id){
        $this->course_id = $course_id;
    }

    public function setSeriesMetadata($seriesMetadata){
        $this->seriesMetadata = $seriesMetadata;
    }

    /*  */

    public function getEpisodes($force_reload = false)
    {
        if ($this->getSeriesID()) {
            $series = $this->getCachedEntries($this->getSeriesID(), $force_reload);

            $stored_episodes = OCModel::getCoursePositions($this->getCourseID());
            $ordered_episodes = array();

            //check if series' episodes is already stored in studip
            if (!empty($series)) {
                // add additional episode metadata from opencast
                $ordered_episodes = $this->episodeComparison($stored_episodes, $series);
            }

            return $this->order_episodes_by(
                array('start','title'),
                array(SORT_NATURAL,SORT_NATURAL),
                array(true,false),
                $ordered_episodes
            );
        } else {
            return false;
        }

    }

    /**
     * This function sorts an array 'deep'. This means the content is first sorted
     * by the first key in the array. If there are more than one entry for this key
     * the episodes in this group are sorted by the second key and so on.
     * @param $keys
     * @param $sort_flags
     * @param $reversed
     * @param $episodes
     *
     * @return array
     */
    private function order_episodes_by($keys,$sort_flags,$reversed,$episodes){
        $ordered = array();

        //Get the current settings for this episode group
        $key = array_shift($keys);
        $current_reversed = array_shift($reversed);
        $current_sort_flags = array_shift($sort_flags);

        //Regroup the current episodes bv the key field
        foreach($episodes as $episode){
            $ordered[$episode[$key]][] = $episode;
        }

        //Sort, reverse if needed
        if($current_reversed){
            krsort($ordered,$current_sort_flags);
        }else{
            ksort($ordered,$current_sort_flags);
        }

        //Now remove the grouping but contain the order within
        $episodes = array();

        foreach ($ordered as $entries){
            if(count($keys)>0 && count($entries)>1){
                $entries = $this->order_episodes_by($keys,$sort_flags,$reversed,$entries);
            }
            foreach($entries as $entry){
                $episodes[] = $entry;
            }
        }

        //Return really ordered list of episodes
        return $episodes;
    }

    private function episodeComparison($stored_episodes, $remote_episodes)
    {
        $episodes = array();
        $oc_episodes = $this->prepareEpisodes($remote_episodes);
        $lastpos;

        foreach($stored_episodes as $key => $stored_episode){

            if ($tmp = $oc_episodes[$stored_episode['episode_id']]){
                $tmp['visibility'] = $stored_episode['visible'];
                $tmp['mkdate']  = $stored_episode['mkdate'];

                OCModel::setEpisode(
                    $stored_episode['episode_id'],
                    $this->getCourseID(),
                    $tmp['visibility'],
                    $stored_episode['mkdate']
                );

                $episodes[] = $tmp;

                unset($oc_episodes[$stored_episode['episode_id']]);
                unset($stored_episodes[$key]);
            }

        }

        //add new episodes
        if (!empty($oc_episodes)) {
            foreach ($oc_episodes as $episode) {
                $lastpos++;
                $timestamp = time();
                $episode['visibility'] = 'true';
                $episode['mkdate'] = $timestamp;

                OCModel::setEpisode(
                    $episode['id'],
                    $this->getCourseID(),
                    'true',
                    $timestamp
                );

                $episodes[] = $episode;
                NotificationCenter::postNotification('NewEpisodeForCourse', array(
                    'episode_id'    => $episode['id'],
                    'course_id'     => $this->getCourseID(),
                    'episode_title' => $episode['title']
                ));
            }

        }

        // removed orphaned episodes
        if (!empty($stored_episodes)) {
            foreach ($stored_episodes as $orphaned_episode) {
                // todo log event for this action
                OCModel::removeStoredEpisode(
                    $orphaned_episode['episode_id'],
                    $this->getCourseID()
                );
            }
        }

        return $episodes;

    }

    private function prepareEpisodes($oc_episodes)
    {
        $episodes = array();

        if(is_array($oc_episodes)){
            foreach($oc_episodes as $episode) {

                if(is_object($episode->mediapackage)){
                    $prespreview = false;

                    foreach($episode->mediapackage->attachments->attachment as $attachment) {
                        if($attachment->type === "presenter/search+preview") $preview = $attachment->url;

                        if($attachment->type === "presentation/player+preview") {
                            $prespreview = $attachment->url;
                        }
                    }

                    foreach($episode->mediapackage->media->track as $track) {
                        // TODO CHECK CONDITIONS FOR MEDIAPACKAGE AUDIO AND VIDEO DL
                        if(($track->type === 'presenter/delivery') && ($track->mimetype === 'video/mp4' || $track->mimetype === 'video/avi')){
                            $url = parse_url($track->url);
                            if(in_array('atom', $track->tags->tag) && $url['scheme'] != 'rtmp') {
                                $presenter_download = $track->url;
                            }
                        }
                        if(($track->type === 'presentation/delivery') && ($track->mimetype === 'video/mp4' || $track->mimetype === 'video/avi')){
                            $url = parse_url($track->url);
                            if(in_array('atom', $track->tags->tag) && $url['scheme'] != 'rtmp') {
                                $presentation_download = $track->url;
                            }
                        }
                        if(($track->type === 'presenter/delivery') && (($track->mimetype === 'audio/mp3') || ($track->mimetype === 'audio/mpeg') || ($track->mimetype === 'audio/m4a')))
                            $audio_download = $track->url;
                    }
                    $episodes[$episode->id] = array('id' => $episode->id,
                        'title' => OCModel::sanatizeContent($episode->dcTitle),
                        'start' => $episode->mediapackage->start,
                        'duration' => $episode->mediapackage->duration,
                        'description' => OCModel::sanatizeContent($episode->dcDescription),
                        'author' => OCModel::sanatizeContent($episode->dcCreator),
                        'preview' => $preview,
                        'prespreview' => $prespreview,
                        'presenter_download' => $presenter_download,
                        'presentation_download' => $presentation_download,
                        'audio_download' => $audio_download,
                    );
                }
            }
        }
        elseif (is_object($oc_episodes)) { // refactor this asap
            if(is_object($oc_episodes->mediapackage)){
                $episode = $oc_episodes;

                foreach($episode->mediapackage->attachments->attachment as $attachment) {
                    if($attachment->type === 'presenter/search+preview') $preview = $attachment->url;
                }

                foreach($episode->mediapackage->media->track as $track) {
                    // TODO CHECK CONDITIONS FOR MEDIAPACKAGE AUDIO AND VIDEO DL
                    if(($track->type === 'presenter/delivery') && ($track->mimetype === 'video/mp4' || $track->mimetype === 'video/avi')){
                        $url = parse_url($track->url);
                        if(in_array('atom', $track->tags->tag) && $url['scheme'] != 'rtmp') {
                            $presenter_download = $track->url;
                        }
                    }
                    if(($track->type === 'presentation/delivery') && ($track->mimetype === 'video/mp4' || $track->mimetype === 'video/avi')){
                        $url = parse_url($track->url);
                        if(in_array('atom', $track->tags->tag) && $url['scheme'] != 'rtmp') {
                            $presentation_download = $track->url;
                        }
                    }
                    if(($track->type === 'presenter/delivery') && (($track->mimetype === 'audio/mp3') || ($track->mimetype === 'audio/mpeg') || ($track->mimetype === 'audio/m4a')))
                        $audio_download = $track->url;
                }
                $episodes[$episode->id] = array('id' => $episode->id,
                    'title' => OCModel::sanatizeContent($episode->dcTitle),
                    'start' => $episode->mediapackage->start,
                    'duration' => $episode->mediapackage->duration,
                    'description' => OCModel::sanatizeContent($episode->dcDescription),
                    'author' => OCModel::sanatizeContent($episode->dcCreator),
                    'preview' => $preview,
                    'presenter_download' => $presenter_download,
                    'presentation_download' => $presentation_download,
                    'audio_download' => $audio_download,
                );
            }
        }

        return $episodes;

    }

    private function getCachedEntries($series_id, $forced_reload)
    {
        $cached_series = OCSeriesModel::getCachedSeriesData($series_id);

        if (!$cached_series || $forced_reload) {
            $search_client = SearchClient::getInstance(OCRestClient::getCourseIdForSeries($series_id));
            $series = $search_client->getEpisodes($series_id, true);

            if ($forced_reload && $cached_series) {
                OCSeriesModel::updateCachedSeriesData($series_id, serialize($series));
            } else {
                OCSeriesModel::setCachedSeriesData($series_id, serialize($series));
            }
        }

        return $cached_series;
    }

    /**
     * return number of new episodes since last visit up to 3 month ago
     *
     * @param string $visitdate count all entries newer than this timestamp
     *
     * @return int the number of entries
     */
    public function getCount($visitdate)
    {
        if ($visitdate < time() - OCCourseModel::LAST_VISIT_MAX) {
            $visitdate = time() - OCCourseModel::LAST_VISIT_MAX;
        }


        $stmt = DBManager::get()->prepare("SELECT COUNT(*) FROM oc_seminar_episodes
            WHERE seminar_id = :seminar_id AND mkdate > :lastvisit");


        $stmt->bindParam(':seminar_id', $this->getCourseID());
        $stmt->bindParam(':lastvisit', $visitdate);

        $stmt->execute();

        return $stmt->fetchColumn();
    }

    public function getEpisodesforREST() {
        $rest_episodes = array();
        $is_dozent = $GLOBALS['perm']->have_studip_perm('dozent', $this->course_id);
        $episodes = $this->getEpisodes();
        foreach($episodes as $episode){
            if($episode['visibility'] == 'true'){
                $rest_episodes[] = $episode;
            } else {
                if($is_dozent){
                    $rest_episodes[] = $episode;
                }
            }
        }
        return $rest_episodes;
    }

    public  function toggleSeriesVisibility() {
        if($this->getSeriesVisibility() == 'visible') $visibility = 'invisible';
        else $visibility = 'visible';
        return OCSeriesModel::updateVisibility($this->course_id,$visibility);

    }

    public function getSeriesVisibility(){
        $visibility = OCSeriesModel::getVisibility($this->course_id);
        return $visibility['visibility'];

    }

    /**
     * refine the list of episodes wrt. the visibility of an episode
     *
     * @param array $ordered_episodes list of all episodes for the given course
     *
     * @return array episodes refined list of episodes - only visible episodes are considered
     */
    public function refineEpisodesForStudents($ordered_episodes) {

        $episodes = array();
        foreach($ordered_episodes as $episode){
            if($episode['visibility'] == 'true') {
                $episodes[] = $episode;
            }
        }
        return $episodes;
    }

    public function getWorkflow($target) {
        $stmt = DBManager::get()->prepare("SELECT * FROM oc_seminar_workflow_configuration
            WHERE seminar_id = ? AND target = ?");

        $stmt->execute(array($this->getCourseID(), $target));
        $workflow =  $stmt->fetchAll(PDO::FETCH_ASSOC);

        if(empty($workflow)) {
            return false;
        }
        else return array_pop($workflow);
    }

    public function setWorkflow($workflow_id, $target) {

        $stmt = DBManager::get()->prepare("INSERT INTO
                oc_seminar_workflow_configuration (seminar_id, workflow_id, target, mkdate, chdate)
                VALUES (?, ?, ?, ?, ?)");
        return $stmt->execute(array($this->getCourseID(), $workflow_id, $target, time(), time()));
    }

    public function updateWorkflow($workflow_id, $target) {

        $stmt = DBManager::get()->prepare("UPDATE
                oc_seminar_workflow_configuration SET workflow_id = ?, chdate = ?
                WHERE seminar_id = ? AND target = ?");
        return $stmt->execute(array( $workflow_id, time(), $this->getCourseID(), $target));
    }

    public function setWorkflowForDate($termin_id, $workflow_id) {
        $stmt = DBManager::get()->prepare("UPDATE
                oc_scheduled_recordings SET workflow_id = ?
                WHERE seminar_id = ? AND date_id = ?");
        return $stmt->execute(array($workflow_id,  $this->getCourseID(), $termin_id));
    }

    public function clearSeminarEpisodes()
    {
        $stmt = DBManager::get()->prepare("DELETE FROM oc_seminar_episodes
            WHERE seminar_id = ?");

        return $stmt->execute(array($this->getCourseID()));
    }
}
