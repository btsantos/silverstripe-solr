<?php

/**
 * A queued job used for reindexing content
 *
 *
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 * @license http://silverstripe.org/bsd-license/
 */
if (class_exists('AbstractQueuedJob')) {
	class SolrReindexJob extends AbstractQueuedJob {
		
		public function __construct($type = null) {
			if (!$type && isset($_GET['type'])) {
				$type = $_GET['type'];
			}
			if ($type) {
				$this->reindexType = $type;
			}
		}

		public function getTitle() {
			return "Reindex $this->reindexType content in Solr";
		}

		public function setup() {
			$this->lastIndexedID = 0;
		}

		/**
		 * To process this job, we need to get the next page whose ID is the next greater than the last
		 * processed. This way we don't need to remember a bunch of data about what we've processed
		 */
		public function process() {
			if (ClassInfo::exists('Subsite')) {
				Subsite::disable_subsite_filter();
			}
			
			$page = DataObject::get_one($this->reindexType, singleton('SolrUtils')->dbQuote(array($this->reindexType . '.ID >' => $this->lastIndexedID)), true, 'ID ASC');
			if (ClassInfo::exists('Subsite')) {
				Subsite::$disable_subsite_filter = false;
			}

			if (!$page || !$page->exists()) {
				$this->isComplete = true;
				return;
			}
			
			$mode = Versioned::get_reading_mode();
			Versioned::reading_stage('Stage');

			// index away
			$service = singleton('SolrSearchService');
			// only explicitly index live/stage versions if the object has the appropriate extension
			if ($page->hasExtension('Versioned')) {
				$service->index($page, 'Stage');
				$live = Versioned::get_one_by_stage($page->ClassName, 'Live', '"ID" = ' . $page->ID);
				if ($live) {
					$service->index($live, 'Live');
					echo "<p>Reindexed Live version of $live->Title</p>\n";
				}
			} else {
				$service->index($page);
			}

			Versioned::set_reading_mode($mode);

			$this->currentStep++;
			$this->lastIndexedID = $page->ID;
		}
	}
}
