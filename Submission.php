<?php 

    class Submission extends PluginBase
    {
        static protected $description = 'Submission: Submit completed surveys to some URL.';
        static protected $name = 'Submission';

        /**
         * Plugin settings
         */
        protected $settings = array(
            'apikey' => array(
                'type' => 'string',
                'label' => 'API key'
            ),
			'url' => array(
                'type' => 'string',
                'label' => 'Target URL'
            ),
			'api_bearer' => array(
				'type' => 'checkbox',
				'label' => 'Send API key as bearer token in http auth header.',
				'default' => true
			),
			'api_data' => array(
				'type' => 'checkbox',
				'label' => 'Send API key as part of the json data.',
				'default' => false
			)
        );
        protected $storage = 'DbStorage';
        
        public function __construct(PluginManager $manager, $id) 
        {
            parent::__construct($manager, $id);
            
            // Provides survey specific settings.
            $this->subscribe('beforeSurveySettings');
            
            // Saves survey specific settings.
            $this->subscribe('newSurveySettings');
            
            // Calls Beenz API on survey completion.
            $this->subscribe('afterSurveyComplete');
        }
        
        /**
         * This event is fired after the survey has been completed.
         * @param PluginEvent $event
         */
        public function afterSurveyComplete()
        {
            $event = $this->getEvent();
            if ($event->get('responseId') == null)
            {
                return;
            }

            // Get the response information.
            $response = $this->pluginManager->getAPI()->getResponse($event->get('surveyId'), $event->get('responseId'));
			// We also add the api key to the data for maximum compatibility.
			$data = array(
				'response' => $response,
				'apikey' => $this->get('apikey')
			);
			$result = $this->postData($data);
			$this->event->setContent($this, $result['contents'], 'submission');

        }

		public function postData($data)
        {
            if ($this->get('apikey') != null)
            {
                $context = stream_context_create(array('http' => array(
                    'method' => 'POST',
                    'user_agent' => 'Limesurvey submission plugin.',
                    'content' => json_encode($data),
                    'header' => array(
                        "Content-Type: application/json",
                        "Accept: application/json",
						"Authorization: Bearer " . $this->get('apikey')
                    ),
                    'timeout' => 10,
                    'ignore_errors' => true
                )));
				$result = file_get_contents($this->get('url'), false, $context);
				$statusCode = intval(explode(' ', $http_response_header[0])[1]);
				return array('code' => $statusCode, 'contents' => $result);
            }
            return 'No data submitted.';
        }
    }


?>