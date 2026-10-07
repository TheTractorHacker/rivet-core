package main

import (
	"crypto/tls"
	"net"
	"sync/atomic"
)

var wireIn, wireOut atomic.Int64 // TLS-layer bytes actually on the TCP connection

type countConn struct{ net.Conn }

func (c countConn) Read(p []byte) (int, error) {
	n, err := c.Conn.Read(p)
	wireIn.Add(int64(n))
	return n, err
}
func (c countConn) Write(p []byte) (int, error) {
	n, err := c.Conn.Write(p)
	wireOut.Add(int64(n))
	return n, err
}

type countListener struct{ net.Listener }

func (l countListener) Accept() (net.Conn, error) {
	c, err := l.Listener.Accept()
	if err != nil {
		return nil, err
	}
	return countConn{c}, nil
}

func tlsPair(p [2][]byte) (tls.Certificate, error) { return tls.X509KeyPair(p[0], p[1]) }

func newTLSListener(addr string, c tls.Certificate) (net.Listener, error) {
	ln, err := net.Listen("tcp", addr)
	if err != nil {
		return nil, err
	}
	return tls.NewListener(countListener{ln}, &tls.Config{Certificates: []tls.Certificate{c}, MinVersion: tls.VersionTLS12}), nil
}
