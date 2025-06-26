(function () {
	const urlParams = new URLSearchParams(window.location.search);
	const activeLocation = urlParams.get("part_filter[storelocation][value]");

	document.querySelectorAll(".btn-group button").forEach((button) => {
		if (button.getAttribute("data-location-id") === activeLocation) {
			button.classList.add("active");
		} else {
			button.classList.remove("active");
		}

		button.addEventListener("click", () => {
			const form = document.querySelector('form[data-controller="helpers--form-cleanup"]');
			const locationField = form.querySelector('[name="part_filter[storelocation][value]"]');
			const operatorField = form.querySelector('[name="part_filter[storelocation][operator]"]');

			const clickedId = button.getAttribute("data-location-id");

			if (button.classList.contains("active")) {
				locationField.value = "";
				operatorField.value = "";
			} else {
				locationField.value = clickedId;
				operatorField.value = "INCLUDING_CHILDREN";
			}

			locationField.dispatchEvent(new Event("change", { bubbles: true }));
			operatorField.dispatchEvent(new Event("change", { bubbles: true }));

			form.requestSubmit();
		});
	});

	const locationValue = document.querySelector('[name="part_filter[storelocation][value]"]');
	if (locationValue) {
		const parentRow = locationValue.closest(".row");
		if (parentRow) {
			parentRow.style.display = "none";
		}
	}
})();
