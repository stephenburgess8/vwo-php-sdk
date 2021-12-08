<?php

/**
 * Copyright 2019-2021 Wingify Software Pvt. Ltd.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *    http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace vwo\Utils;

use http\Url;
use Monolog\Logger as Logger;
use vwo\Constants\EventEnum;
use vwo\Constants\LogMessages as LogMessages;
use vwo\Constants\Urls;
use vwo\Services\LoggerService as LoggerService;
use vwo\Utils\UuidUtil;
use vwo\Utils\Common as CommonUtil;

class ImpressionBuilder
{
    /**
     * sdk version for api hit
     */
    const SDK_VERSION = '1.25.0';
    /**
     * sdk langauge for api hit
     */
    const SDK_LANGUAGE = 'php';

    const CLASSNAME = 'vwo\Utils\ImpressionBuilder';

    public static function getVisitorQueryParams($accountId, $campaign, $userId, $combination, $sdkKey)
    {
        $params = array(
            'ed' => '{"p":"server"}',
        );

        $params = self::mergeCommonTrackingQueryParams(
            $accountId,
            $campaign,
            $userId,
            $combination,
            $params,
            $sdkKey
        );

        return $params;
    }

    public static function getConversionQueryParams($accountId, $campaign, $userId, $combination, $goal, $revenueValue, $sdkKey)
    {
        $params = array(
            'goal_id' => $goal['id']
        );

        if ($goal['type'] == "REVENUE_TRACKING" && (is_string($revenueValue) || is_float(
            $revenueValue
        ) || is_int($revenueValue))
        ) {
            $params['r'] = $revenueValue;
        }

        $params = self::mergeCommonTrackingQueryParams(
            $accountId,
            $campaign,
            $userId,
            $combination,
            $params,
            $sdkKey
        );

        return $params;
    }

    public static function getSettingsFileQueryParams($accountId, $sdkKey)
    {
        $params = array(
            'a' => $accountId,
            'i' => $sdkKey,
            'r' => CommonUtil::getRandomNumber(),
            'platform' => 'server',
            'api-version' => 1
        );

        $params = self::mergeCommonQueryParams($params);
        unset($params['env']);

        return $params;
    }

    public static function getPushQueryParams($accountId, $userId, $sdkKey, $tagKey, $tagValue)
    {
        $params = [
            'tags' => '{"u":{"' . $tagKey . '":"' . $tagValue . '"}}'
        ];

        $params = self::mergeTrackingCallParams($accountId, $userId, $params);
        $params = self::mergeCommonQueryParams($params, $sdkKey);

        return $params;
    }

    /**
     *
     * @param $accountId
     * @param $userId
     * @param array $params    - tomerge with
     *
     * @return array
     */
    public static function mergeCommonTrackingQueryParams($accountId, $campaign, $userId, $combination, $params = [], $sdkKey = '')
    {
        $params['experiment_id'] = $campaign['id'];
        $params['combination'] = $combination; // variation id
        $params['ap'] = 'server';

        $params = self::mergeTrackingCallParams($accountId, $userId, $params);
        $params = self::mergeCommonQueryParams($params, $sdkKey);

        return $params;
    }

    public static function mergeCommonQueryParams($params = [], $sdkKey = '')
    {
        $params['sdk-v'] = self::SDK_VERSION;
        $params['sdk'] = self::SDK_LANGUAGE;
        if ($sdkKey) {
            $params['env'] = $sdkKey;
        }

        return $params;
    }

    public static function mergeTrackingCallParams($accountId, $userId, $params = [])
    {
        $params['account_id'] = $accountId;
        $params['sId'] = time();
        $params['u'] = UuidUtil::get($userId, $accountId);

        $params['random'] = time() / 10;

        return $params;
    }

    /**
     * Builds generic properties for different tracking calls required by VWO servers.
     *
     * @param  int    $accountId
     * @param  String $sdkKey
     * @param  String $eventName
     * @param  array  $usageStats
     * @return array $properties
     */
    public static function getEventsBaseProperties($accountId, $sdkKey, $eventName, $usageStats = [])
    {
         $properties = [
             "en" => $eventName,
             "a" => $accountId,
             "env" => $sdkKey,
             "eTime" => CommonUtil::getCurrentUnixTimestampInMillis(),
             "random" => CommonUtil::getRandomNumber(),
             "p" => "FS"
         ];
         if ($eventName == EventEnum::VWO_VARIATION_SHOWN) {
             $properties = array_merge($properties, $usageStats);
         }
         return $properties;
    }

    /**
     * Builds generic payload required by all the different tracking calls.
     *
     * @param  array  $configObj  setting-file
     * @param  String $userId
     * @param  String $eventName
     * @param  array  $usageStats
     * @return array $properties
     */
    public static function getEventBasePayload($configObj, $userId, $eventName, $usageStats = [])
    {
        $uuid = UuidUtil::get($userId, $configObj["accountId"]);
        $sdkKey = $configObj["sdkKey"];

        $props = [
            'sdkName' => self::SDK_LANGUAGE,
            'sdkVersion' => self::SDK_VERSION,
            '$visitor' => [
                'props' => [
                  'vwo_fs_environment' => $sdkKey
                ]
            ]
        ];

        //        if ($usageStats) {
        //            $props = array_merge($props, $usageStats);
        //        }

        $properties = [
            "d" => [
                "msgId" => $uuid . "-" . time(),
                "visId" => $uuid,
                "sessionId" => time(),
                "event" => [
                  "props" => $props,
                  "name" => $eventName,
                  "time" => CommonUtil::getCurrentUnixTimestampInMillis()
                ],
                "visitor" => [
                  "props" => [
                    "vwo_fs_environment" => $sdkKey
                  ]
                ]
            ]
        ];

        return $properties;
    }

    /**
     * Builds payload to track the visitor.
     *
     * @param  array  $configObj   setting-file
     * @param  String $userId
     * @param  String $eventName
     * @param  int    $campaignId
     * @param  int    $variationId
     * @param  array  $usageStats
     * @return array $properties
     */
    public static function getTrackUserPayloadData($configObj, $userId, $eventName, $campaignId, $variationId, $usageStats = [])
    {
        $properties = self::getEventBasePayload($configObj, $userId, $eventName);

        $properties["d"]["event"]["props"]["id"] = $campaignId;
        $properties["d"]["event"]["props"]["variation"] = $variationId;

        // this is currently required by data-layer team, we can make changes on DACDN and remove it from here
        $properties["d"]["event"]["props"]["isFirst"] = 1;

        LoggerService::log(
            Logger::DEBUG,
            LogMessages::DEBUG_MESSAGES['IMPRESSION_FOR_EVENT_ARCH_TRACK_USER'],
            [
                '{a}' => $configObj["accountId"],
                '{u}' => $userId,
                '{c}' => $campaignId,
            ],
            self::CLASSNAME
        );

        return $properties;
    }

    /**
     * Builds payload to track the Goal.
     *
     * @param  array  $configObj    setting-file
     * @param  String $userId
     * @param  String $eventName
     * @param  int    $revenueValue
     * @param  array  $metricMap
     * @param  array  $revenueProps
     * @return array $properties
     */
    public static function getTrackGoalPayloadData($configObj, $userId, $eventName, $revenueValue, $metricMap, $revenueProps = [])
    {
        $properties = self::getEventBasePayload($configObj, $userId, $eventName);

        $metric = [];
        foreach ($metricMap as $campaignId => $goalId) {
            $metric["id_$campaignId"] = ["g_$goalId"];
            LoggerService::log(
                Logger::DEBUG,
                LogMessages::DEBUG_MESSAGES['IMPRESSION_FOR_EVENT_ARCH_TRACK_GOAL'],
                [
                    '{goalName}' => $eventName,
                    '{a}' => $configObj["accountId"],
                    '{u}' => $userId,
                    '{c}' => $campaignId
                ],
                self::CLASSNAME
            );
        }

        $properties["d"]["event"]["props"]["vwoMeta"] = [
            "metric" => $metric
        ];

        if(count($revenueProps) && $revenueValue) {
            foreach ($revenueProps as $revenueProp) {
                $properties["d"]["event"]["props"]["vwoMeta"][$revenueProp] = $revenueValue;
            }
        }

        $properties['d']['event']['props']['isCustomEvent'] = true;

        return $properties;
    }

    /**
     * Builds payload to appply post segmentation on VWO campaign reports.
     *
     * @param  array  $configObj          setting-file
     * @param  String $userId
     * @param  String $eventName
     * @param  array  $customDimensionMap
     * @return array $properties
     */
    public static function getPushPayloadData($configObj, $userId, $eventName, $customDimensionMap = [])
    {
        $properties = self::getEventBasePayload($configObj, $userId, $eventName);

        $properties['d']['event']['props']['isCustomEvent'] = true;
        foreach ($customDimensionMap as $key => $value) {
            $properties['d']['event']['props']['$visitor']['props'][$key] = $value;
            $properties['d']['visitor']['props'][$key] = $value;
        }

        LoggerService::log(
            Logger::DEBUG,
            LogMessages::DEBUG_MESSAGES['IMPRESSION_FOR_EVENT_ARCH_PUSH'],
            [
                '{a}' => $configObj["accountId"],
                '{u}' => $userId,
                '{property}' => json_encode($customDimensionMap)
            ],
            self::CLASSNAME
        );

        return $properties;
    }
}
