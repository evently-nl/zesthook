<?php

/**
 * Send a webhook POST/GET request after each afterSurveyComplete event
 *
 * @author Stefan Verweij <stefan@evently.nl>
 * @copyright 2016 Evently <https://www.evently.nl>
 * @license GPL v3
 * @version 2.1.0
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
 */
class ZestHook extends PluginBase
{
    protected $storage = 'DbStorage';
    static protected $description = 'Webhook for LimeSurvey: send survey_id, response_id and token on every response submission.';
    static protected $name = 'ZestHook';
    protected $surveyId;

    public function init()
    {
        $this->subscribe('afterSurveyComplete');
        $this->subscribe('beforeSurveySettings');
        $this->subscribe('newSurveySettings');
    }

    protected $settings = array(
        'bUse' => array(
            'type'    => 'select',
            'options' => array(
                0 => 'No',
                1 => 'Yes'
            ),
            'default' => 1,
            'label'   => 'Send a hook for every survey by default?',
            'help'    => 'Overwritable in each Survey setting'
        ),
        'sUrl' => array(
            'type'    => 'string',
            'default' => 'https://zest.evently.nl/api/1/ping',
            'label'   => 'The default address to send the webhook to',
            'help'    => 'If you are using Zest, this should be https://zest.evently.nl/api/1/ping'
        ),
        'sAuthToken' => array(
            'type'  => 'string',
            'label' => 'Zest Platform API Token',
            'help'  => 'To get a token logon to your account and click on the Tokens tab'
        ),
    );

    /**
     * Add per-survey settings.
     */
    public function beforeSurveySettings()
    {
        $oEvent = $this->event;
        $oEvent->set("surveysettings.{$this->id}", array(
            'name'     => get_class($this),
            'settings' => array(
                'bUse' => array(
                    'type'    => 'select',
                    'label'   => 'Send a hook for this survey',
                    'options' => array(
                        0 => 'No',
                        1 => 'Yes',
                        2 => 'Use site settings (default)'
                    ),
                    'default' => 2,
                    'help'    => 'Leave default to use global setting',
                    'current' => $this->get('bUse', 'Survey', $oEvent->get('survey'))
                ),
                'bUrlOverwrite' => array(
                    'type'    => 'select',
                    'label'   => 'Overwrite the global Hook Url',
                    'options' => array(
                        0 => 'No',
                        1 => 'Yes'
                    ),
                    'default' => 0,
                    'help'    => 'Set to Yes if you want to use a specific URL for this survey',
                    'current' => $this->get('bUrlOverwrite', 'Survey', $oEvent->get('survey'))
                ),
                'sUrl' => array(
                    'type'    => 'string',
                    'label'   => 'The address to send the hook for this survey to',
                    'help'    => 'Leave blank to use global setting',
                    'current' => $this->get('sUrl', 'Survey', $oEvent->get('survey'))
                ),
                'bAuthTokenOverwrite' => array(
                    'type'    => 'select',
                    'label'   => 'Overwrite the global authorization token',
                    'options' => array(
                        0 => 'No',
                        1 => 'Yes'
                    ),
                    'default' => 0,
                    'help'    => 'Set to Yes if you want to use a specific API token for this survey',
                    'current' => $this->get('bAuthTokenOverwrite', 'Survey', $oEvent->get('survey'))
                ),
                'sAuthToken' => array(
                    'type'    => 'string',
                    'label'   => 'API Token for this survey',
                    'help'    => 'Leave blank to use default',
                    'current' => $this->get('sAuthToken', 'Survey', $oEvent->get('survey'))
                ),
                'bRequestType' => array(
                    'type'    => 'select',
                    'label'   => 'Request Type',
                    'default' => 0,
                    'options' => array(
                        0 => 'POST',
                        1 => 'GET'
                    ),
                    'current' => $this->get('bRequestType', 'Survey', $oEvent->get('survey'))
                ),
                'bDebugMode' => array(
                    'type'    => 'select',
                    'options' => array(
                        0 => 'No',
                        1 => 'Yes'
                    ),
                    'default' => 0,
                    'label'   => 'Enable Debug Mode',
                    'help'    => 'Shows transmitted data to the respondent. Disable for live surveys.',
                    'current' => $this->get('bDebugMode', 'Survey', $oEvent->get('survey')),
                )
            )
        ));
    }

    /**
     * Save per-survey settings.
     */
    public function newSurveySettings()
    {
        $event = $this->event;
        foreach ($event->get('settings') as $name => $value) {
            $default = $event->get($name, null, null, isset($this->settings[$name]['default']) ? $this->settings[$name]['default'] : null);
            $this->set($name, $value, 'Survey', $event->get('survey'), $default);
        }
    }

    /**
     * Build and dispatch the webhook on survey completion.
     * Payload: survey_id, response_id, and token (when present).
     */
    public function afterSurveyComplete()
    {
        $timeStart      = microtime(true);
        $oEvent         = $this->getEvent();
        $this->surveyId = $oEvent->get('surveyId');

        if ($this->isHookDisabled()) {
            return;
        }

        $url = ($this->get('bUrlOverwrite', 'Survey', $this->surveyId) === '1')
            ? $this->get('sUrl', 'Survey', $this->surveyId)
            : $this->get('sUrl', null, null, $this->settings['sUrl']['default']);

        $auth = ($this->get('bAuthTokenOverwrite', 'Survey', $this->surveyId) === '1')
            ? $this->get('sAuthToken', 'Survey', $this->surveyId)
            : $this->get('sAuthToken');

        $responseId = $oEvent->get('responseId');
        $response   = $this->api->getResponse($this->surveyId, $responseId);

        $payload = array(
            'survey_id'   => $this->surveyId,
            'response_id' => $responseId,
        );
        if (!empty($response['token'])) {
            $payload['token'] = $response['token'];
        }
        $jsonBody = json_encode($payload);

        $requestType = $this->get('bRequestType', 'Survey', $this->surveyId);
        if ($requestType == 1) {
            $hookSent = $this->httpGet($url, $auth, $jsonBody);
        } else {
            $hookSent = $this->httpPost($url, $auth, $jsonBody);
        }

        $this->debug($jsonBody, $hookSent, $timeStart, $response);
    }

    /**
     * Send a JSON POST request. The api_token is appended as a query parameter for
     * endpoints that authenticate via URL (e.g. Zest API).
     */
    private function httpPost($url, $apiToken, $jsonBody)
    {
        $fullUrl = $apiToken ? $url . '?api_token=' . urlencode($apiToken) : $url;

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL            => $fullUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonBody,
            CURLOPT_HTTPHEADER     => array(
                'Content-Type: application/json',
                'Accept: application/json',
            ),
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ));

        $output = curl_exec($ch);
        if ($output === false) {
            $output = 'cURL error: ' . curl_error($ch);
        }
        curl_close($ch);

        return $output;
    }

    /**
     * Send a GET request with all payload fields appended as query parameters.
     */
    private function httpGet($url, $apiToken, $jsonBody)
    {
        $params = (array) json_decode($jsonBody, true);
        if ($apiToken) {
            $params['api_token'] = $apiToken;
        }
        $fullUrl = $url . '?' . http_build_query($params, '', '&');

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL            => $fullUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ));

        $output = curl_exec($ch);
        if ($output === false) {
            $output = 'cURL error: ' . curl_error($ch);
        }
        curl_close($ch);

        return $output;
    }

    /**
     * Returns true if the webhook should NOT be sent for the current survey.
     */
    private function isHookDisabled()
    {
        $surveySetting = $this->get('bUse', 'Survey', $this->surveyId);
        if ($surveySetting == 0) {
            return true;
        }
        if ($surveySetting == 2) {
            return $this->get('bUse') == 0;
        }
        return false;
    }

    private function debug($jsonBody, $hookSent, $timeStart, $response)
    {
        if ($this->get('bDebugMode', 'Survey', $this->surveyId) != 1) {
            return;
        }
        $elapsed = round(microtime(true) - $timeStart, 4);
        $html  = '<pre>';
        $html .= '<strong>Payload sent:</strong>' . "\n" . htmlspecialchars($jsonBody);
        $html .= "\n\n-----------------------------\n\n";
        $html .= '<strong>Response received:</strong>' . "\n" . htmlspecialchars(print_r($hookSent, true));
        $html .= "\n\n-----------------------------\n\n";
        $html .= '<strong>Survey response data:</strong>' . "\n" . htmlspecialchars(print_r($response, true));
        $html .= "\n\n-----------------------------\n\n";
        $html .= 'Execution time: ' . $elapsed . 's';
        $html .= '</pre>';

        $event = $this->getEvent();
        $event->getContent($this)->addContent($html);
    }
}
