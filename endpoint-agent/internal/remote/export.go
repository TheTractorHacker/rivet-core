package remote

// Exported forms of the small result helpers, for job handlers that live in their own package (internal/patch).

// Fail is a failed job that ran: {"v":1,"ok":false,"detail":...} plus any identifying members.
func Fail(detail string, extra map[string]any) Result { return failure(detail, extra) }

// Cannot is a failure before anything ran (bad parameters): the executor reports it without an exit code.
func Cannot(detail string) Result { return cannotLaunch(detail) }

// SanitizeDetail keeps a detail code short, printable and single line.
func SanitizeDetail(s string) string { return sanitizeDetail(s) }

// FitList renders a document holding a list so that it fits maxOut (entries are dropped from the END, never cut text); see fitList.
func FitList(total int, alreadyTruncated bool, maxOut int, render func(n int, truncated bool) string) (string, bool) {
	return fitList(total, alreadyTruncated, maxOut, render)
}
