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
                $this->log($model->id, $result['code'], $model->getSurveyId(), $result['contents'], 'DELETE');
            } catch (\Throwable $t) {
                $this->log($model->id, 0, $model->getSurveyId(), $t->getMessage(), 'DELETE');
            }
        }

        private function getResponse($surveyId, $responseId)
        {
            $response = \SurveyDynamic::model($surveyId)->findByPk($responseId);
            if (!isset($response)) {
                return null;
            }
            $fieldmap = createFieldMap($surveyId, 'full',null, false, $response->attributes['startlanguage']);
            $result = [];
            Yii::import('application.helpers.viewHelper');
            foreach($response->attributes as $key => $value) {
                if (isset($fieldmap[$key])) {
                    $result[viewHelper::getFieldCode($fieldmap[$key])] = $value;
                } else {
                    $result[$key] = $value;
                }
            }
            return $result;
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
                } elseif (null === $response = $this->getResponse($surveyId, $responseId)) {
                    die('Response not found');
                } else {
                    $result = $this->postData($this->createData($response, $surveyId));
                    die(strip_tags($result['contents']));
                }
            }
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
            $response = $this->getResponse($event->get('surveyId'), $event->get('responseId'));

			$result = $this->postData($this->createData($response, $event->get('surveyId')));
            $this->log($event->get('responseId'), $result['code'], $event->get('surveyId'), $result['contents'], 'POST');
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
            if ($this->get('apiKey', null, null, false))
            {
                $headers[] = "Authorization: Be:arer " . $this->get('apiKey', null, null, '');
            }
            $context = stream_context_create(array('http' => array(
                'method' => 'DELETE',
                'user_agent' => 'Limesurvey submission plugin.',
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

		private function log($responseId, $code, $surveyId, $result, string $method)
        {
            $result = substr($result, 0, 500);
            $line = date(DateTime::ATOM) . " : $method : $code : $responseId : $surveyId : $result\n";
            file_put_contents(__DIR__ . '/submission.log', $line, FILE_APPEND);
        }
    }


?>
