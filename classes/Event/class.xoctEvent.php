<?php
require_once('./Customizing/global/plugins/Services/Repository/RepositoryObject/OpenCast/classes/Object/class.xoctObject.php');
require_once('./Customizing/global/plugins/Services/Repository/RepositoryObject/OpenCast/classes/Request/class.xoctRequest.php');
require_once('./Customizing/global/plugins/Services/Repository/RepositoryObject/OpenCast/classes/Event/Publication/class.xoctPublication.php');
require_once('./Customizing/global/plugins/Services/Repository/RepositoryObject/OpenCast/classes/Request/class.xoctUploadFile.php');
require_once('./Customizing/global/plugins/Services/Repository/RepositoryObject/OpenCast/classes/Event/Publication/class.xoctMedia.php');
require_once('./Customizing/global/plugins/Services/Repository/RepositoryObject/OpenCast/classes/Conf/PublicationUsage/class.xoctPublicationUsage.php');

/**
 * Class xoctEvent
 *
 * @author Fabian Schmid <fs@studer-raimann.ch>
 */
class xoctEvent extends xoctObject {

	const STATE_SUCCEEDED = 'SUCCEEDED';
	/**
	 * @var string
	 */
	protected static $publication_preview = 'engage-player';


	/**
	 * @param array $filter
	 *
	 * @return xoctEvent[]
	 */
	public static function getFiltered(array $filter) {
		$check_cache = count($filter) == 1 AND isset($filter['series']);
		if ($check_cache) {
			$key = 'unfiltered_list' . $filter['series'];
			$existing = xoctCache::getInstance()->get($key);
			if ($existing) {
				return $existing;
			}
		}

		/**
		 * @var $xoctEvent xoctEvent
		 */
		$request = xoctRequest::root()->events();
		if ($filter) {
			$filter_string = '';
			foreach ($filter as $k => $v) {
				$filter_string .= $k . ':' . $v . '';
			}

			$request->parameter('filter', $filter_string);
		}
		$request->parameter('limit', 1000);
		$data = json_decode($request->get());
		$return = array();
		foreach ($data as $d) {
			$xoctEvent = xoctEvent::find($d->identifier);
			$return[] = $xoctEvent->__toArray();
		}
		if ($check_cache) {
			xoctCache::getInstance()->set($key, $return);
		}

		return $return;
	}


	/**
	 * @param string $identifier
	 */
	public function __construct($identifier = '') {
		if ($identifier) {
			$this->setIdentifier($identifier);
			$this->read();
		}
	}


	public function read() {
		$data = json_decode(xoctRequest::root()->events($this->getIdentifier())->get());
		$this->loadFromStdClass($data);
		$this->loadMetadata();
		$this->setCreated(new DateTime($data->created));
		$this->setStartTime(new DateTime($data->start_time));
		$this->loadPublications();
		if ($this->getIdentifier()) {
			$this->setSeriesIdentifier($this->getMetadata()->getField('isPartOf')->getValue());
		}
		$this->loadAcl();
	}


	public function create() {
		$data = array();

		$this->setMetadata(xoctMetadata::getSet(xoctMetadata::FLAVOR_DUBLINCORE_EPISODES));
		$this->updateMetadataFromFields();

		$data['metadata'] = json_encode(array( $this->getMetadata()->__toStdClass() ));

		$processing = $this->getProcessing();
		$data['processing'] = json_encode($processing);

		//		$data['acl'] = json_encode(xoctAcl::getStandardSet());
		$acls = array();
		foreach ($this->getAcls() as $acl) {
			$acls[] = $acl->__toStdClass();
		}
		$data['acl'] = json_encode($acls);

		$presenter = xoctUploadFile::getInstanceFromFileArray('file_presenter');
		$data['presenter'] = $presenter->getCurlString();

		$return = json_decode(xoctRequest::root()->events()->post($data));

		$this->setIdentifier($return->identifier);
	}


	public function update() {
		$data = array();
		$this->updateMetadataFromFields();
		$data['metadata'] = json_encode(array( $this->getMetadata()->__toStdClass() ));

		$acls = array();
		foreach ($this->getAcls() as $acl) {
			$acls[] = $acl->__toStdClass();
		}
		$data['acl'] = json_encode($acls);

		xoctRequest::root()->events($this->getIdentifier())->post($data);
	}


	public function updateSeries() {
		$this->getMetadata()->getField('isPartOf')->setValue($this->getSeriesIdentifier());
	}


	public function delete() {
	}


	/**
	 * @return string
	 */
	public function getThumbnailUrl() {
		$thumbnail = $this->getPublicationMetadataForUsage(xoctPublicationUsage::find(xoctPublicationUsage::USAGE_THUMBNAIL));
		if (! $thumbnail) {
			$thumbnail = $this->getPublicationMetadataForUsage(xoctPublicationUsage::find(xoctPublicationUsage::USAGE_THUMBNAIL_FALLBACK));
		}

		return $thumbnail ? $thumbnail : './Customizing/global/plugins/Services/Repository/RepositoryObject/OpenCast/templates/images/no_preview.png';
	}


	protected function getPublicationMetadataForUsage($thumbnail) {
		/**
		 * @var $thumbnail  xoctPublicationUsage
		 * @var $attachment xoctAttachment
		 * @var $media      xoctMedia
		 */
		$medias = array();
		$attachments = array();
		foreach ($this->getPublications() as $publication) {
			$medias = array_merge($medias, $publication->getMedia());
			$attachments = array_merge($attachments, $publication->getAttachments());
		}
		if ($thumbnail instanceof xoctPublicationUsage) {
			switch ($thumbnail->getMdType()) {
				case xoctPublicationUsage::MD_TYPE_ATTACHMENT:
					foreach ($attachments as $attachment) {
						if ($attachment->getFlavor() == $thumbnail->getFlavor()) {
							return $attachment->getUrl();
						}
					}
					break;
				case xoctPublicationUsage::MD_TYPE_MEDIA:
					foreach ($medias as $media) {
						if ($media->getFlavor() == $thumbnail->getFlavor()) {
							return $media->getUrl();
						}
					}
					break;
				case xoctPublicationUsage::MD_TYPE_PUBLICATION_ITSELF:
					break;
			}
		}

		return NULL;
	}


	/**
	 * @return xoctPublication
	 * @deprecated
	 */
	public function getPresentationPublication() {
		foreach ($this->getPublications() as $publication) {
			if ($publication->getChannel() == self::$publication_preview) {
				return $publication;
			}
		}

		return new xoctPublication();
	}


	/**
	 * @return xoctAttachment|xoctMedia
	 * @deprecated
	 */
	public function getPreviewAttachment() {
		//		xoctCache::flushAll();
		$xoctPublicationUsage = xoctPublicationUsage::getUsage(xoctPublicationUsage::USAGE_API);
		foreach ($this->getPublications() as $publication) {
			if ($publication->getChannel() == $xoctPublicationUsage->getChannel()) {
				switch ($xoctPublicationUsage->getMdType()) {
					case xoctPublicationUsage::MD_TYPE_ATTACHMENT:
						foreach ($publication->getAttachments() as $attachment) {
							if ($attachment->getFlavor() == $xoctPublicationUsage->getFlavor()) {
								return $attachment;
							}
						}

						return new xoctAttachment();
						break;
					case xoctPublicationUsage::MD_TYPE_MEDIA:
						foreach ($publication->getMedia() as $media) {
							if ($media->getFlavor() == $xoctPublicationUsage->getFlavor()) {
								return $media;
							}
						}

						return new xoctMedia();
						break;
				}
			}
		}
		$xoctMedia = new xoctMedia();
		$xoctMedia->setUrl('/Customizing/global/plugins/Services/Repository/RepositoryObject/OpenCast/templates/images/no_preview.png');

		return $xoctMedia;
	}


	protected function loadPublications() {
		$data = json_decode(xoctRequest::root()->events($this->getIdentifier())->publications()->get());
		$publications = array();
		foreach ($data as $d) {
			$p = new xoctPublication();
			$p->loadFromStdClass($d);
			$publications[] = $p;
		}
		$this->setPublications($publications);
	}


	protected function loadAcl() {
		$data = json_decode(xoctRequest::root()->events($this->getIdentifier())->acl()->get());
		$acls = array();
		foreach ($data as $d) {
			$p = new xoctAcl();
			$p->loadFromStdClass($d);
			$acls[] = $p;
		}
		$this->setAcls($acls);
	}


	public function loadMetadata() {
		if ($this->getIdentifier()) {
			$data = json_decode(xoctRequest::root()->events($this->getIdentifier())->metadata()->get());

			foreach ($data as $d) {
				if ($d->flavor == xoctMetadata::FLAVOR_DUBLINCORE_EPISODES) {
					$xoctMetadata = new xoctMetadata();
					$xoctMetadata->loadFromStdClass($d);
					$this->setMetadata($xoctMetadata);
				}
			}
		}
		if (! $this->getMetadata()) {
			$this->setMetadata(xoctMetadata::getSet(xoctMetadata::FLAVOR_DUBLINCORE_SERIES));
		}
	}


	/**
	 * @var string
	 */
	protected $identifier = '';
	/**
	 * @var int
	 */
	protected $archive_version;
	/**
	 * @var DateTime
	 */
	protected $created;
	/**
	 * @var string
	 */
	protected $creator;
	/**
	 * @var Array
	 */
	protected $contributors;
	/**
	 * @var string
	 */
	protected $description;
	/**
	 * @var int
	 */
	protected $duration;
	/**
	 * @var bool
	 */
	protected $has_previews;
	/**
	 * @var string
	 */
	protected $location;
	/**
	 * @var Array
	 */
	protected $presenters;
	/**
	 * @var array
	 */
	protected $publication_status;
	/**
	 * @var array
	 */
	protected $processing_state;
	/**
	 * @var DateTime
	 */
	protected $start_time;
	/**
	 * @var array
	 */
	protected $subjects;
	/**
	 * @var string
	 */
	protected $title;
	/**
	 * @var xoctPublication[]
	 */
	protected $publications;
	/**
	 * @var xoctMetadata
	 */
	protected $metadata = NULL;
	/**
	 * @var xoctAcl[]
	 */
	protected $acls = array();
	/**
	 * @var string
	 */
	protected $series_identifier = '';


	/**
	 * @return string
	 */
	public function getIdentifier() {
		return $this->identifier;
	}


	/**
	 * @param string $identifier
	 */
	public function setIdentifier($identifier) {
		$this->identifier = $identifier;
	}


	/**
	 * @return int
	 */
	public function getArchiveVersion() {
		return $this->archive_version;
	}


	/**
	 * @param int $archive_version
	 */
	public function setArchiveVersion($archive_version) {
		$this->archive_version = $archive_version;
	}


	/**
	 * @return DateTime
	 */
	public function getCreated() {
		return $this->created;
	}


	/**
	 * @param DateTime $created
	 */
	public function setCreated($created) {
		$this->created = $created;
	}


	/**
	 * @return string
	 */
	public function getCreator() {
		return $this->creator;
	}


	/**
	 * @param string $creator
	 */
	public function setCreator($creator) {
		$this->creator = $creator;
	}


	/**
	 * @return Array
	 */
	public function getContributors() {
		return $this->contributors;
	}


	/**
	 * @param Array $contributors
	 */
	public function setContributors($contributors) {
		$this->contributors = $contributors;
	}


	/**
	 * @return string
	 */
	public function getDescription() {
		return $this->description;
	}


	/**
	 * @param string $description
	 */
	public function setDescription($description) {
		$this->description = $description;
	}


	/**
	 * @return int
	 */
	public function getDuration() {
		return $this->duration;
	}


	/**
	 * @param int $duration
	 */
	public function setDuration($duration) {
		$this->duration = $duration;
	}


	/**
	 * @return boolean
	 */
	public function isHasPreviews() {
		return $this->has_previews;
	}


	/**
	 * @param boolean $has_previews
	 */
	public function setHasPreviews($has_previews) {
		$this->has_previews = $has_previews;
	}


	/**
	 * @return string
	 */
	public function getLocation() {
		return $this->location;
	}


	/**
	 * @param string $location
	 */
	public function setLocation($location) {
		$this->location = $location;
	}


	/**
	 * @return Array
	 */
	public function getPresenters() {
		return $this->presenters;
	}


	/**
	 * @param Array $presenters
	 */
	public function setPresenters($presenters) {
		$this->presenters = $presenters;
	}


	/**
	 * @return array
	 */
	public function getPublicationStatus() {
		return $this->publication_status;
	}


	/**
	 * @param array $publication_status
	 */
	public function setPublicationStatus($publication_status) {
		$this->publication_status = $publication_status;
	}


	/**
	 * @return array
	 */
	public function getProcessingState() {
		return $this->processing_state;
	}


	/**
	 * @param array $processing_state
	 */
	public function setProcessingState($processing_state) {
		$this->processing_state = $processing_state;
	}


	/**
	 * @return DateTime
	 */
	public function getStartTime() {
		return $this->start_time;
	}


	/**
	 * @param DateTime $start_time
	 */
	public function setStartTime($start_time) {
		$this->start_time = $start_time;
	}


	/**
	 * @return array
	 */
	public function getSubjects() {
		return $this->subjects;
	}


	/**
	 * @param array $subjects
	 */
	public function setSubjects($subjects) {
		$this->subjects = $subjects;
	}


	/**
	 * @return string
	 */
	public function getTitle() {
		return $this->title;
	}


	/**
	 * @param string $title
	 */
	public function setTitle($title) {
		$this->title = $title;
	}


	/**
	 * @return xoctPublication[]
	 */
	public function getPublications() {
		return $this->publications;
	}


	/**
	 * @param xoctPublication[] $publications
	 */
	public function setPublications($publications) {
		$this->publications = $publications;
	}


	/**
	 * @return xoctMetadata
	 */
	public function getMetadata() {
		return $this->metadata;
	}


	/**
	 * @param xoctMetadata $metadata
	 */
	public function setMetadata(xoctMetadata $metadata) {
		$this->metadata = $metadata;
	}


	/**
	 * @return xoctAcl[]
	 */
	public function getAcls() {
		return $this->acls;
	}


	/**
	 * @param xoctAcl[] $acls
	 */
	public function setAcls($acls) {
		$this->acls = $acls;
	}


	/**
	 * @param xoctAcl $acl
	 */
	public function addAcl(xoctAcl $acl) {
		$this->acls[] = $acl;
	}


	/**
	 * @return string
	 */
	public function getSeriesIdentifier() {
		return $this->series_identifier;
	}


	/**
	 * @param string $series_identifier
	 */
	public function setSeriesIdentifier($series_identifier) {
		$this->series_identifier = $series_identifier;
	}


	protected function updateMetadataFromFields() {
		$title = $this->getMetadata()->getField('title');
		$title->setValue($this->getTitle());

		$description = $this->getMetadata()->getField('description');
		$description->setValue($this->getDescription());

		$subjects = $this->getMetadata()->getField('subjects');
		$subjects->setValue(array());

		$is_part_of = $this->getMetadata()->getField('isPartOf');
		$is_part_of->setLabel('EVENTS.EVENTS.DETAILS.METADATA.SERIES');
		$is_part_of->setValue($this->getSeriesIdentifier());

		$startDate = $this->getMetadata()->getField('startDate');
		$startDate->setValue(date('Y-m-d'));

		$startTime = $this->getMetadata()->getField('startTime');
		$startTime->setValue(date('H:i'));
	}


	/**
	 * @return stdClass
	 */
	protected function getProcessing() {
		require_once('./Customizing/global/plugins/Services/Repository/RepositoryObject/OpenCast/classes/Conf/class.xoctConf.php');
		$processing = new stdClass();
		$processing->workflow = xoctConf::get(xoctConf::F_WORKFLOW);
		$processing->configuration->flagForCutting = "false";
		$processing->configuration->flagForReview = "false";
		$processing->configuration->publishToEngage = "false";
		$processing->configuration->publishToHarvesting = "false";
		$processing->configuration->straightToPublishing = "false";

		return $processing;
	}
}