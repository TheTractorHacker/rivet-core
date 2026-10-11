package remote

import (
	"errors"
	"strconv"
	"strings"
	"time"
)

// ClockTicks is the kernel USER_HZ. It is 100 on every Linux architecture the
// agent ships for; Go has no sysconf without cgo, so it is a constant.
const ClockTicks = 100

// ProcStat is the parsed /proc/<pid>/stat of one process.
type ProcStat struct {
	Comm      string
	PPID      int
	UTime     uint64 // clock ticks
	STime     uint64
	Threads   int
	StartTick uint64 // since boot, clock ticks
	RSSPages  int64
}

// ParseProcStat parses one /proc/<pid>/stat line. The command name sits between
// the first '(' and the LAST ')' and may itself contain spaces and parentheses.
func ParseProcStat(line string) (ProcStat, error) {
	line = strings.TrimSpace(line)
	open, closeIdx := strings.IndexByte(line, '('), strings.LastIndexByte(line, ')')
	if open < 0 || closeIdx < open {
		return ProcStat{}, errors.New("malformed stat")
	}
	st := ProcStat{Comm: line[open+1 : closeIdx]}
	f := strings.Fields(line[closeIdx+1:])
	if len(f) < 22 { // state ppid ... rss is field index 21
		return st, errors.New("short stat")
	}
	num := func(i int) (uint64, error) { return strconv.ParseUint(f[i], 10, 64) }
	var err error
	var v uint64
	if v, err = num(1); err != nil {
		return st, err
	}
	st.PPID = int(v)
	if st.UTime, err = num(11); err != nil {
		return st, err
	}
	if st.STime, err = num(12); err != nil {
		return st, err
	}
	if v, err = num(17); err != nil {
		return st, err
	}
	st.Threads = int(v)
	if st.StartTick, err = num(19); err != nil {
		return st, err
	}
	rss, err := strconv.ParseInt(f[21], 10, 64)
	if err != nil {
		return st, err
	}
	st.RSSPages = rss
	return st, nil
}

// CPUTime is utime+stime.
func (s ProcStat) CPUTime() time.Duration {
	return time.Duration(s.UTime+s.STime) * time.Second / ClockTicks
}

// ParsePasswd maps uid -> user name from /etc/passwd content.
func ParsePasswd(content string) map[string]string {
	m := map[string]string{}
	for _, line := range strings.Split(content, "\n") {
		f := strings.Split(line, ":")
		if len(f) >= 3 && f[0] != "" && !strings.HasPrefix(f[0], "#") {
			if _, ok := m[f[2]]; !ok {
				m[f[2]] = f[0]
			}
		}
	}
	return m
}
