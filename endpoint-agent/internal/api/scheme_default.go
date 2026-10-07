//go:build !agenttest

package api

import (
	"errors"
	"net/url"
)

func checkScheme(u *url.URL) error {
	if u.Scheme != "https" {
		return errors.New("server URL must use https")
	}
	return nil
}
