# Next JS
Example files intended to work with the utility belt.

## `api/preview.js`
The endpoint used for Craft's Live Preview. You'll also want to add a header to 
allow the site to be used in an iframe in craft:

```js
// next.config.js
module.exports = {
	// ...
    
	async headers () {
		return [
			{
				source: '/(.*?)',
				headers: [
					{
						key: 'Content-Security-Policy',
						value: 'frame-ancestors \'self\' ' + process.env.ALLOWED_FRAME_ANCESTORS,
					},
				],
			},
		];
	},
    
    // ...
};
```

```dotenv
ALLOWED_FRAME_ANCESTORS=https://dev.mysite.com
```

## `api/revalidate.js`
The endpoint used to automatically revalidate URI's when an entry is saved in Craft.