<?php declare(strict_types = 0);

namespace Modules\AnalistProblem\Actions;

use CController;
use API;
use Exception;

class CControllerAnalistServicePopup extends CController {

	private static $sliCache = [];
	private static $cacheExpiry = 300;

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function checkInput(): bool {
		$fields = [
			'hostname' => 'string',
			'hostid' => 'string', 
			'eventid' => 'string',
			'triggerid' => 'string',
			'serviceid' => 'string',
			'output' => 'string',
			'selectParents' => 'string',
			'selectChildren' => 'string',
			'selectTags' => 'string',
			'selectProblemTags' => 'string'
		];

		$ret = $this->validateInput($fields);

		return $ret;
	}

	protected function doAction(): void {
		
		header('Content-Type: application/json');
		
		$response = ['success' => false];

		try {
			
			$params = [
				'hostname' => $this->getInput('hostname', ''),
				'hostid' => $this->getInput('hostid', ''),
				'eventid' => $this->getInput('eventid', ''),
				'triggerid' => $this->getInput('triggerid', ''),
				'serviceid' => $this->getInput('serviceid', '')
			];

			$event_tags = [];
			if (!empty($params['eventid'])) {
				$event_tags = $this->getEventTags($params['eventid']);
			}

			if ($params['serviceid']) {
				
				$response = $this->getServiceDetails($params['serviceid']);
			} else {
				
				$response = $this->getServices($params, $event_tags);
			}

		} catch (Exception $e) {
			$response = [
				'success' => false,
				'error' => [
					'title' => _('Error'),
					'message' => $e->getMessage()
				]
			];
		}

		echo json_encode($response);
		exit;
	}

	private function getServices(array $params, array $event_tags = []): array {
		try {
			
			$service_params = [
				'output' => 'extend',
				'evaltype' => 2,
				'selectParents' => 'extend',
				'selectChildren' => 'extend',
				'selectTags' => 'extend',
				'selectProblemTags' => 'extend',
				'sortfield' => 'name',
				'sortorder' => 'ASC'
			];

			if (!empty($event_tags)) {
				$problem_tags = [];
				foreach ($event_tags as $tag) {
					if (isset($tag['tag'])) {
						$problem_tag = ['tag' => $tag['tag']];
						
						$problem_tag['value'] = $tag['value'] ?? '';
						$problem_tags[] = $problem_tag;
					}
				}
				
				if (!empty($problem_tags)) {
					$service_params['problem_tags'] = $problem_tags;
				}
			}

			$services = API::Service()->get($service_params);

			$enriched_services = [];
			foreach ($services as $service) {
				
				$sli_data = $this->getSLIDataOptimized($service['serviceid']);
				
				if ($sli_data) {
					$service['sli'] = $sli_data['sli'];
					$service['slo'] = $sli_data['slo'] ?? null;
					$service['uptime'] = $sli_data['uptime'];
					$service['downtime'] = $sli_data['downtime'];
					$service['error_budget'] = $sli_data['error_budget'];
					$service['has_sla'] = $sli_data['has_sla'];
					$service['sla_id'] = $sli_data['sla_id'] ?? null;
					$service['sla_name'] = $sli_data['sla_name'] ?? null;
				} else {
					$service['sli'] = null;
					$service['slo'] = null;
					$service['uptime'] = null;
					$service['downtime'] = null;
					$service['error_budget'] = null;
					$service['has_sla'] = false;
					$service['sla_id'] = null;
					$service['sla_name'] = null;
				}

				$service['hierarchy_path'] = $this->getServiceHierarchyPath($service['serviceid']);
				
				$enriched_services[] = $service;
			}
			
			return [
				'success' => true,
				'data' => $enriched_services,
				'filters_applied' => [
					'event_tags' => $event_tags,
					'problem_tags_used' => $service_params['problem_tags'] ?? [],
					'eventid' => $params['eventid'] ?? null,
					'hostid' => $params['hostid'] ?? null,
					'hostname' => $params['hostname'] ?? null
				]
			];
			
		} catch (Exception $e) {
			return [
				'success' => false,
				'error' => [
					'title' => _('Error'),
					'message' => $e->getMessage()
				]
			];
		}
	}

	private function getServiceDetails(string $serviceid): array {
		try {
			
			$services = API::Service()->get([
				'output' => 'extend',
				'serviceids' => [$serviceid],
				'selectParents' => 'extend',
				'selectChildren' => 'extend',
				'selectTags' => 'extend',
				'selectProblemTags' => 'extend',
				'selectStatusRules' => 'extend'
			]);

			if (empty($services)) {
				throw new Exception(_('Service not found'));
			}

			$service = $services[0];

			$sli_data = $this->getSLIDataOptimized($serviceid);
			
			if ($sli_data) {
				
				$service['sli'] = $sli_data['sli'];
				$service['slo'] = $sli_data['slo'] ?? null;
				$service['uptime'] = $sli_data['uptime'];
				$service['downtime'] = $sli_data['downtime'];
				$service['error_budget'] = $sli_data['error_budget'];
				$service['has_sla'] = $sli_data['has_sla'];
				$service['sla_id'] = $sli_data['sla_id'] ?? null;
				$service['sla_name'] = $sli_data['sla_name'] ?? null;
			} else {
				
				$service['sli'] = null;
				$service['slo'] = null;
				$service['uptime'] = null;
				$service['downtime'] = null;
				$service['error_budget'] = null;
				$service['has_sla'] = false;
				$service['sla_id'] = null;
				$service['sla_name'] = null;
			}

			$service['sla_info'] = $this->getSLAInfo($serviceid);

			return [
				'success' => true,
				'data' => $service
			];

		} catch (Exception $e) {
			return [
				'success' => false,
				'error' => [
					'title' => _('Error'),
					'message' => $e->getMessage()
				]
			];
		}
	}

	private function getSLIDataOptimized(string $serviceid): ?array {
		
		$cacheKey = 'sli_' . $serviceid;
		if (isset(self::$sliCache[$cacheKey])) {
			$cached = self::$sliCache[$cacheKey];
			if ($cached['expiry'] > time()) {
				return $cached['data'];
			}
			unset(self::$sliCache[$cacheKey]);
		}

		try {
			
			$sli_data = $this->fastSlaLookup($serviceid);
			
			if ($sli_data) {
				
				self::$sliCache[$cacheKey] = [
					'data' => $sli_data,
					'expiry' => time() + self::$cacheExpiry
				];
				return $sli_data;
			}

			self::$sliCache[$cacheKey] = [
				'data' => null,
				'expiry' => time() + 30
			];
			return null;
			
		} catch (Exception $e) {
			
			self::$sliCache[$cacheKey] = [
				'data' => null,
				'expiry' => time() + 15
			];
			return null;
		}
	}

	private function fastSlaLookup(string $serviceid): ?array {
		
		if (!class_exists('API') || !method_exists('API', 'SLA')) {
			return null;
		}

		try {
			
			$slas = API::SLA()->get([
				'output' => ['slaid', 'name', 'slo'],
				'serviceids' => [$serviceid],
				'limit' => 1
			]);
			
			if (empty($slas)) {
				return null;
			}

			$sla = $slas[0];

			$sli_response = API::SLA()->getSli([
				'slaid' => $sla['slaid'],
				'serviceids' => [(int)$serviceid],
				'periods' => 1,
				'period_from' => time()
			]);
			
			if (empty($sli_response) || 
				!isset($sli_response['serviceids'], $sli_response['sli'])) {
				return null;
			}

			$serviceids = $sli_response['serviceids'];
			$sli_data_array = $sli_response['sli'];
			
			$service_index = array_search((int)$serviceid, $serviceids);
			if ($service_index === false || 
				!isset($sli_data_array[$service_index]) || 
				empty($sli_data_array[$service_index])) {
				return null;
			}

			$period_data = end($sli_data_array[$service_index]);
			
			return [
				'sli' => $period_data['sli'] ?? null,
				'slo' => isset($sla['slo']) ? (float) $sla['slo'] : null,
				'uptime' => $this->formatDuration($period_data['uptime'] ?? 0),
				'downtime' => $this->formatDuration($period_data['downtime'] ?? 0),
				'error_budget' => $this->formatDuration($period_data['error_budget'] ?? 0),
				'excluded_downtimes' => $period_data['excluded_downtimes'] ?? [],
				'has_sla' => true,
				'method' => 'optimized_sla_api',
				'sla_name' => $sla['name'],
				'sla_id' => $sla['slaid']
			];
			
		} catch (Exception $e) {
			return null;
		}
	}

	private function formatDuration(int $seconds): string {
		
		$is_negative = $seconds < 0;
		$abs_seconds = abs($seconds);
		
		if ($abs_seconds <= 0) {
			return '0s';
		}

		$days = floor($abs_seconds / 86400);
		$hours = floor(($abs_seconds % 86400) / 3600);
		$minutes = floor(($abs_seconds % 3600) / 60);
		$secs = $abs_seconds % 60;

		$parts = [];
		if ($days > 0) $parts[] = $days . 'd';
		if ($hours > 0) $parts[] = $hours . 'h';
		if ($minutes > 0) $parts[] = $minutes . 'm';
		if ($secs > 0 && empty($parts)) $parts[] = $secs . 's';

		$formatted = implode(' ', array_slice($parts, 0, 2)); 
		
		return $is_negative ? '-' . $formatted : $formatted;
	}

	private function getSLAInfo(string $serviceid): array {
		try {
			return [
				'name' => 'Default SLA',
				'target' => '99.9%'
			];
		} catch (Exception $e) {
			return [];
		}
	}

	private function getEventTags(string $eventid): array {
		try {
			$events = API::Event()->get([
				'output' => ['eventid'],
				'selectTags' => 'extend',
				'eventids' => [$eventid],
				'limit' => 1
			]);

			if (!empty($events) && !empty($events[0]['tags'])) {
				return $events[0]['tags'];
			}

			return [];

		} catch (Exception $e) {
			return [];
		}
	}

	private function getServiceHierarchyPath(string $serviceid): array {
		try {
			$path = [];
			$current_serviceid = $serviceid;
			$visited = [];

			while ($current_serviceid && !in_array($current_serviceid, $visited)) {
				$visited[] = $current_serviceid;

				$services = API::Service()->get([
					'output' => 'extend',
					'serviceids' => [$current_serviceid],
					'selectParents' => 'extend',
					'selectTags' => 'extend',
					'selectProblemTags' => 'extend'
				]);
				
				if (empty($services)) {
					break;
				}
				
				$service = $services[0];

				array_unshift($path, [
					'serviceid' => $service['serviceid'],
					'name' => $service['name'],
					'status' => $service['status'],
					'algorithm' => $service['algorithm'],
					'tags' => $service['tags'],
					'problem_tags' => $service['problem_tags']
				]);

				if (!empty($service['parents'])) {
					
					$current_serviceid = $service['parents'][0]['serviceid'];
				} else {
					
					break;
				}
			}
			
			return $path;
			
		} catch (Exception $e) {
			return [];
		}
	}
}
