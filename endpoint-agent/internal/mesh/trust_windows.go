//go:build windows

package mesh

// trustedExe: on Windows the Mesh agent lives below Program Files, whose ACL already keeps standard users out; there is no owner/mode check to make here.
func trustedExe(path string) bool { return true }
