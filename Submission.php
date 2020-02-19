<?php 

    class Submission extends PluginBase
    {
        static protected $description = 'Submission: Submit completed surveys to some URL.';
        static protected $name = 'Submission';

        /**
         * Plugin settings
         */
        protected $settings = array(
            'apiKey' => array(
                'type' => 'string',
                'label' => 'API key'
            ),
			'url' => array(
                'type' => 'string',
                'label' => 'Target URL'
            ),
			'apiBearer' => array(
				'type' => 'checkbox',
				'default' => true,
				'label' => 'Send API key in bearer token',
			),
			'apiData' => array(
				'type' => 'checkbox',
				'default' => false,
				'label' => 'Send API key in json data',
			)
        );
        protected $storage = 'DbStorage';
        
        public function __construct(PluginManager $manager, $id) 
        {
            parent::__construct($manager, $id);
            
            $this->subscribe('afterSurveyComplete');
            $this->subscribe('beforeSurveySettings');
            $this->subscribe('afterModelDelete');
            $this->subscribe('newDirectRequest');
        }
        public function afterModelDelete()
        {
            $event = $this->event;
            $model = $event->get('model');
            if (!$model instanceof Response) {
                return;
            }
            try {
                $result = $this->deleteData($model);
                $this->log($model->id, $result['code'], $model->getSurveyId(), $result['contents']);
            } catch (\Throwable $t) {
                $this->log($model->id, 0, $model->getSurveyId(), $t->getMessage());
            }
        }

        public function newDirectRequest()
        {
            $request = $this->api->getRequest();
            if ($request->isPostRequest
                && $this->api->checkAccess('administrator')
                && $this->event->get('function') == 'manualSubmit'
            ) {
                $responseId = $request->getPost('responseId');
                $surveyId = $request->getParam('surveyId');
                if (empty($responseId)) {
                    die('You must select a response.');
                } elseif (null === $response = $this->api->getResponse($surveyId, $responseId)) {
                    die('Response not found');
                } else {
                    $result = $this->postData($this->createData($response, $surveyId));
                    die(strip_tags($result['contents']));
                }
            }
        }

        public function beforeSurveySettings()
        {
            $event = $this->event;
            try {
                $items[''] = "";
                /** @var Response $response */
                foreach($this->api->getResponses($event->get('survey'), [], 'submitdate is not null', [
                    'limit' => 5
                ]) as $response) {
                    $label = "#{$response->id}";
                    /** @var Token $token */
                    if (null !== $token = $response->getRelated('token')) {
                        $label .= " by {$token->firstname} {$token->firstname}";
                    }
                    $label .= " on {$response->submitdate}";
                    $items[$response->id] = $label;
                }

            } catch(\Throwable $e) {
                $items = [];
            }

            if (empty($items)) {
                return;
            }

            $settings = [
                'name' => get_class($this),
                'settings' => [
                    'manualSubmit' => [
                        'type' => 'select',
                        'label' => 'Manual submission: ',
                        'options' => $items,
                        'selectOptions' => [
                            'placeholder' => "Pick a response for submission."
                        ]
                    ],
                    'submitButton' => [
                        'type' => 'link',
                        'label' => 'Do it',
                        'link' => $this->api->createUrl('plugins/direct', [
                            'plugin' => $this->getName(),
                            'function' => 'manualSubmit',
                            'surveyId' => $this->event->get('survey')
                        ]),
                        'htmlOptions' => [
                            'id' => 'manualSubmit',
                            'onclick' => <<<JS
return (function() {
     var url = $('#manualSubmit').attr("href")
     $('#manualSubmit').css('enabled', false);
     
     $.post(url, {
        responseId : $('[name="plugin[Submission][manualSubmit]"]').val()
     }, function(data) { 
         var \$div = $('<div>')
         $('<iframe>').attr('srcdoc', data).css({
             width: '100%',
             height: '100%'
         }).appendTo(\$div);
         \$div.dialog({
             width: 500,
             height: 500,
         });
         $('#manualSubmit').css('enabled', true);
     });
     return false;
})();

JS
                            ,
                            'style' => implode(';', [
                                'border-radius: 5px',
                                'background-color: green'

                            ]),

                        ]

                    ]
                ],
            ];
            $event->set("surveysettings.{$this->id}", $settings);
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

			$result = $this->postData($this->createData($response, $event->get('surveyId')));
            $this->log($event->get('responseId'), $result['code'], $event->get('surveyId'), $result['contents']);
			$this->event->setContent($this, $result['contents'], 'submission');

        }

        private function createData(array $response, $surveyId)
        {
            // We also add the api key to the data for maximum compatibility.
            $data = [
                'response' => $response,
                'surveyId' => $surveyId
            ];
            if ($this->get('apiData', null, null, false))
            {
                $data['apiKey'] = $this->get('apiKey');
            }
            return $data;
        }

        private function deleteData(Response $model): array
        {
            $headers = array(
                "Accept: application/json",
            );
            if ($this->get('apiHeader', null, null, false))
            {
                $headers[] = "Authorization: Bearer " . $this->get('apiKey', null, null, '');
            }
            $context = stream_context_create(array('http' => array(
                'method' => 'DELETE',
                'user_agent' => 'Limesurvey submission plugin.',
                'content' => json_encode([
                    'surveyId' => $model->getSurveyId(),
                    'responseId' => $model->id
                ]),
                'header' => $headers,
                'timeout' => 10,
                'ignore_errors' => true
            )));
            $url = $this->get('url');
            $url .= strpos($url, '?') === false ? '?' : '&';
            $url .= http_build_query([
                'surveyId' => $model->getSurveyId(),
                'responseId' => $model->id
            ]);
            $result = file_get_contents($url, false, $context);
            $statusCode = intval(explode(' ', $http_response_header[0])[1]);
            return ['code' => $statusCode, 'contents' => $result];
        }

        private function postData($data)
        {
            $headers = array(
				"Content-Type: application/json",
				"Accept: application/json",
			);
			if ($this->get('apiKey', null, null, false))
			{
				$headers[] = "Authorization: Bearer " . $this->get('apiKey', null, null, '');
			}
			$context = stream_context_create(array('http' => array(
				'method' => 'POST',
				'user_agent' => 'Limesurvey submission plugin.',
				'content' => json_encode($data),
				'header' => $headers,
				'timeout' => 10,
				'ignore_errors' => true
			)));
			$result = file_get_contents($this->get('url'), false, $context);
			$statusCode = intval(explode(' ', $http_response_header[0])[1]);
            return ['code' => $statusCode, 'contents' => $result];
		}

		private function log($responseId, $code, $surveyId, $result)
        {
            $line = date(DateTime::ATOM) . " : $code : $responseId : $surveyId : $result\n";
            file_put_contents(__DIR__ . '/submission.log', $line, FILE_APPEND);
        }
    }


?>
