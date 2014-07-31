var app = angular.module('crowdinpublish', ['ngRoute']);

app.config(function($logProvider){
    $logProvider.debugEnabled(true);
});

app.directive('djfmFileInput', function() {
	return {
		restrict: 'A',
		link: function(scope, element, attrs) {
			angular.element(element).on('change', function() {
				scope.$eval(attrs.djfmFileInput + ' = ' + 'files', {files: element[0].files})
			});
		}
	};
});

app.directive('djfmConfirmClick', function() {
	return {
		restrict: 'A',
		link: function(scope, element, attrs) {
			angular.element(element).on('click', function(event) {
				if (!confirm(attrs.djfmConfirmClick || 'Are you sure you want to proceed with this dangerous action?'))
					event.preventDefault();
			});
		}
	};
});

function toVersion(v, obj) {
	obj.version_number = v;
	obj.version_header = parseInt(obj.version_header, 10);
	return obj;
}

app.config(['$routeProvider', function($routeProvider) {
	$routeProvider
	.when('/', {
		templateUrl: 'views/index.html',
		controller: 'IndexCtrl'
	})
	.when('/versions/:version_number/edit', {
		templateUrl: 'views/edit_version.html',
		controller: 'NewEditVersionCtrl'
	})
	.otherwise({
		redirectTo: '/'
	});
}]);

app.controller('IndexCtrl', function($scope, $http) {
	$http.get('versions').then(function(resp) {
		if (resp.data.success) {
			$scope.versions = resp.data.data;
		}
	})
});

app.controller('NewVersionCtrl', function($scope, $http) {
	$scope.version = {};
});

app.controller('NewEditVersionCtrl', function($scope, $http, $injector, $routeParams, $location) {

	if ($routeParams.version_number) {
		
		$scope.new_entity = false;

		$http.get('versions/' + $routeParams.version_number).then(function(resp) {
			
			if (resp.data.success) {
				$scope.version = toVersion($routeParams.version_number, resp.data.data);
			}
			else {
				$scope.error = "Could not find this version on the server."
			}

		});
	} else {
		$scope.new_entity = true;
		$scope.version = {};
	}

	$scope.submitVersionForm = function() {
		$scope.error = null;
		$scope.success = null;

		var data = new FormData();

		data.append('version_header', $scope.version.version_header);
		if ($scope.version.archive && $scope.version.archive.length === 1) {
			data.append('archive', $scope.version.archive[0]);
		}

		if ($scope.new_entity) {
			data.append('version_number', $scope.version.version_number);
			data.append('new_entity', 1);
		}

		$http.post('versions/' + $scope.version.version_number, data, {
			headers: {'Content-Type': undefined},
			transformRequest: function(data) {
				return data;
			}
		})
		.then(function(resp) {
			if (resp.data.success) {
				$scope.success = resp.data.message || 'Looks good!';
				if ($scope.new_entity)
				{
					$scope.versions.push($scope.version.version_number);
					$scope.version = {};
				}
			} else {
				$scope.error = resp.data.message || 'Unspecified error. The developer is lazy.'
			}
		});
	};

	$scope.deleteVersion = function(version_number) {
		$http.post('versions/' + version_number + '/delete')
		.then(function(resp) {
			if (resp.data.success) {
				$location.path('/').replace();
			} else {
				$scope.error = resp.data.message || 'Unspecified error. The developer is lazy.';
			}
		});
	};

});
