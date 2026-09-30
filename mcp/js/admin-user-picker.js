(function () {
	'use strict';

	var input = document.getElementById('mcp_uid');
	var list = document.getElementById('mcp_uid_list');
	if (!input || !list || typeof OC === 'undefined') {
		return;
	}

	var endpoint = OC.generateUrl('/apps/mcp/api/users');
	var byDisplayName = {};
	var timer = null;
	var seq = 0;
	var controller = null;

	function clear() {
		list.textContent = '';
		byDisplayName = {};
	}

	function render(users) {
		clear();
		users.forEach(function (user) {
			byDisplayName[user.displayName] = user.uid;
			var option = document.createElement('option');
			option.value = user.uid;
			option.label = user.displayName;
			list.appendChild(option);
		});
	}

	function search(term) {
		var id = ++seq;
		if (controller) {
			controller.abort();
		}
		controller = new AbortController();
		fetch(endpoint + '?search=' + encodeURIComponent(term), {
			headers: { requesttoken: OC.requestToken },
			signal: controller.signal
		}).then(function (response) {
			if (!response.ok) {
				throw new Error('user search failed');
			}
			return response.json();
		}).then(function (users) {
			if (id === seq) {
				render(users);
			}
		}).catch(function () {
			if (id === seq) {
				clear();
			}
		});
	}

	input.addEventListener('input', function () {
		clearTimeout(timer);
		var term = input.value.trim();
		if (term.length < 1) {
			clear();
			return;
		}
		timer = setTimeout(function () { search(term); }, 250);
	});

	input.addEventListener('change', function () {
		var value = input.value.trim();
		if (byDisplayName[value]) {
			input.value = byDisplayName[value];
		}
	});
})();
