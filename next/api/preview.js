import fetchGql, { gql } from '../../util/fetchGql';

export default async (req, res) => {
	try {
		const isPreview = !!req.query['x-craft-live-preview'];

		if (!req.query.uid || !isPreview) {
			return res
				.status(401)
				.json({ message: 'Not allowed to access this route' });
		}

		const data = await fetchGql(gql`
            query GetPreview ($uid: String) {
                entry (uid: [$uid]) {
                    level
                    slug
                    url
                }
            }
		`, {
			uid: req.query.uid,
		});

		if (!data?.entry?.url) {
			return res.status(404).json({
				message: `URL of the entry "${req.query.uid}" could not be fetched`,
			});
		}

		res.setPreviewData({
			token: req.query.token,
		});

		let { url } = data.entry;

		const parsedUrl = new URL(url);
		res.redirect(parsedUrl.pathname);
	} catch (e) {
		return res.status(500).json({
			message: e.message,
		});
	}
}