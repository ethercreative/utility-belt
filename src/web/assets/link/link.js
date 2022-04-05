window.utilityBelt_initLinkField = () => {
	const linkFields = document.querySelectorAll('.linkField');

	Array.from(linkFields).forEach(field => {
		if (field.__linkFieldInitialized) return;
		field.__linkFieldInitialized = true;

		const select = field.querySelector('select')
			, typeFields = Array.from(field.querySelectorAll('[data-type]'));

		const updateTypes = () => {
			typeFields.forEach(type => {
				if (type.dataset.type === select.value) {
					type.querySelector('input[type=hidden]')?.removeAttribute('disabled');
					type.removeAttribute('hidden');
				} else {
					type.querySelector('input[type=hidden]')?.setAttribute('disabled', '');
					type.setAttribute('hidden', '');
				}
			});
		};

		updateTypes();
		select.addEventListener('change', updateTypes);
	});
};